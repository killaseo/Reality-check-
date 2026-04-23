<?php
define('CACHE_FILE',       __DIR__ . '/cache.json');
define('KNOWLEDGE_CUTOFF', '2025-08-31');
define('TIMEZONE',         'Europe/Warsaw');
define('CACHE_MAX_AGE',    1800);

const CLAUDE_REF = [
    'btc'     => ['value' => 85000, 'label' => '~$85k'],
    'eth'     => ['value' => 3200,  'label' => '~$3200'],
    'gold'    => ['value' => 2400,  'label' => '~$2400/oz'],
    'usd_pln' => ['value' => 3.90,  'label' => '~3.90'],
    'usd_eur' => ['value' => 0.92,  'label' => '~0.92'],
    'usd_gbp' => ['value' => 0.79,  'label' => '~0.79'],
];

if (isset($_GET['refresh'])) {
    @include __DIR__ . '/fetch_cache.php';
    header('Location: ai.php');
    exit;
}

$cache = null; $cacheAge = null; $cacheStale = false;
if (file_exists(CACHE_FILE)) {
    $raw = file_get_contents(CACHE_FILE);
    if ($raw) {
        $cache     = json_decode($raw, true);
        $cacheAge  = time() - ($cache['meta']['generated_unix'] ?? 0);
        $cacheStale= $cacheAge > CACHE_MAX_AGE;
    }
}

$now     = new DateTimeImmutable('now', new DateTimeZone(TIMEZONE));
$utc     = $now->setTimezone(new DateTimeZone('UTC'));
$daysOld = (int)(($now->getTimestamp() - (new DateTimeImmutable(KNOWLEDGE_CUTOFF))->getTimestamp()) / 86400);

$m       = $cache['market']        ?? [];
$cx      = $cache['crucix_extra']  ?? [];
$news    = $cache['news']          ?? [];
$models  = $cache['model_cutoffs'] ?? [];
$alerts  = $cache['meta']['alerts']           ?? [];
$quality = $cache['meta']['quality']          ?? [];
$crucixOn= $cache['meta']['crucix_connected'] ?? false;

function drift(?float $live, float $ref): ?float {
    if (!$live) return null;
    return ($live - $ref) / $ref * 100;
}
function driftClass(?float $p): string {
    if ($p===null) return 'neutral';
    if ($p> 8) return 'up-strong'; if ($p> 1) return 'up';
    if ($p<-8) return 'down-strong'; if ($p<-1) return 'down';
    return 'neutral';
}
function fmtNum(?float $v, int $dec=2): string {
    return $v!==null ? number_format($v,$dec,'.',',' ) : 'N/A';
}
function fmtDrift(?float $p): string { return $p!==null ? sprintf('%+.1f%%',$p) : '—'; }
function fmtAge(int $s): string {
    if ($s<60) return "{$s}s temu";
    if ($s<3600) return floor($s/60)."min temu";
    return floor($s/3600)."h temu";
}
function catColor(string $c): string {
    return ['ai'=>'#b87fff','tech'=>'#50c8ff','world'=>'#ffdc64','pl'=>'#4dff91'][$c]??'#888';
}

// Raw outputs
if (isset($_GET['json'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($cache, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
    exit;
}
if (isset($_GET['xml'])) {
    header('Content-Type: text/plain; charset=utf-8');
    $cg = $cache['meta']['generated'] ?? $utc->format('c');
    echo "<context_snapshot generated=\"{$cg}\" cache_age_sec=\"{$cacheAge}\">\n";
    echo "  <temporal>\n";
    echo "    <utc_iso>{$utc->format('c')}</utc_iso>\n";
    echo "    <unix>{$now->getTimestamp()}</unix>\n";
    echo "    <local_time timezone=\"".TIMEZONE."\">{$now->format('l, d F Y H:i:s T')}</local_time>\n";
    echo "    <model_knowledge_cutoff>".KNOWLEDGE_CUTOFF."</model_knowledge_cutoff>\n";
    echo "    <days_since_cutoff>{$daysOld}</days_since_cutoff>\n";
    echo "  </temporal>\n";
    echo "  <market_live>\n";
    foreach (['btc'=>'btc_usd','eth'=>'eth_usd','gold'=>'gold_oz_usd','usd_pln'=>'usd_pln','usd_eur'=>'usd_eur'] as $k=>$tag) {
        $v=$m[$k]??null;
        echo "    <{$tag}>".($v!==null?round($v,4):'unavailable')."</{$tag}>\n";
    }
    echo "  </market_live>\n";
    echo "  <model_reference>\n";
    foreach (CLAUDE_REF as $k=>$ref) echo "    <{$k}_ref label=\"{$ref['label']}\">{$ref['value']}</{$k}_ref>\n";
    echo "  </model_reference>\n";
    if (!empty($cx) && $crucixOn) {
        echo "  <crucix_signals>\n";
        if (!empty($cx['vix']))     echo "    <vix>{$cx['vix']}</vix>\n";
        if (!empty($cx['oil_wti'])) echo "    <wti_usd>{$cx['oil_wti']}</wti_usd>\n";
        if (!empty($cx['spy']))     echo "    <spy_usd>{$cx['spy']}</spy_usd>\n";
        if (!empty($cx['acled_count'])) echo "    <conflict_events>{$cx['acled_count']}</conflict_events>\n";
        echo "  </crucix_signals>\n";
    }
    if (!empty($alerts)) {
        echo "  <alerts>\n";
        foreach ($alerts as $a) echo "    <alert asset=\"{$a['asset']}\" change=\"".sprintf('%+.1f%%',$a['change'])."\" level=\"{$a['label']}\"/>\n";
        echo "  </alerts>\n";
    }
    if (!empty($models)) {
        echo "  <ai_model_cutoffs source=\"github.com/HaoooWang/llm-knowledge-cutoff-dates\">\n";
        foreach (array_slice($models, 0, 15) as $mod) {
            $rel = $mod['reliable'] ? " reliable=\"{$mod['reliable']}\"" : '';
            $nm  = htmlspecialchars($mod['name'], ENT_XML1);
            $co  = htmlspecialchars($mod['company'], ENT_XML1);
            echo "    <model name=\"{$nm}\" company=\"{$co}\" cutoff=\"{$mod['cutoff']}\"{$rel}/>\n";
        }
        echo "  </ai_model_cutoffs>\n";
    }
    echo "</context_snapshot>\n";
    exit;
}

// Prepare news by category
$cats    = ['ai','tech','world','pl'];
$catLbls = ['ai'=>'AI / TECH','tech'=>'TECH','world'=>'ŚWIAT','pl'=>'POLSKA'];
$byCat   = [];
foreach ($news as $feed) $byCat[$feed['cat']][] = $feed;
$firstCat = '';
foreach ($cats as $c) { if (!empty($byCat[$c])) { $firstCat=$c; break; } }

// Prepare model vendors
$byVendor = [];
foreach ($models as $mod) $byVendor[$mod['company']][] = $mod;
$vendorColors = [
    'Anthropic'=>'#50c8ff','OpenAI'=>'#4dff91','Google'=>'#ffdc64',
    'Meta'=>'#ff8c42','DeepSeek'=>'#b87fff','xAI'=>'#ff4d6a',
    'Qwen'=>'#42d4f4','Mistral'=>'#f0a500',
];
?><!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>AI Reality Check Terminal</title>
<style>
@import url('https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;600;700&display=swap');
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#060810;--s1:rgba(255,255,255,.025);--bd:rgba(80,200,255,.1);--bd2:rgba(80,200,255,.22);
  --cyan:#50c8ff;--green:#4dff91;--red:#ff4d6a;--yellow:#ffdc64;--purple:#b87fff;
  --muted:#445566;--text:#bccfdf;--white:#f0f4f8;--font:'IBM Plex Mono','Courier New',monospace;
}
body{background:var(--bg);color:var(--text);font-family:var(--font);min-height:100vh;padding:18px 12px 60px;overflow-x:hidden;position:relative}
body::before{content:'';position:fixed;inset:0;pointer-events:none;z-index:0;
  background-image:linear-gradient(rgba(80,200,255,.018) 1px,transparent 1px),linear-gradient(90deg,rgba(80,200,255,.018) 1px,transparent 1px);background-size:32px 32px}
body::after{content:'';position:fixed;inset:0;pointer-events:none;z-index:0;
  background:repeating-linear-gradient(0deg,transparent,transparent 2px,rgba(0,0,0,.06) 2px,rgba(0,0,0,.06) 4px)}
.wrap{position:relative;z-index:1;max-width:900px;margin:0 auto}
.hdr{text-align:center;margin-bottom:22px}
.hdr-eye{font-size:9px;letter-spacing:.4em;color:var(--cyan);opacity:.55;text-transform:uppercase;margin-bottom:5px}
.hdr-clock{font-size:clamp(36px,9vw,58px);font-weight:700;color:var(--white);letter-spacing:.06em;text-shadow:0 0 40px rgba(80,200,255,.3);font-variant-numeric:tabular-nums;line-height:1}
.hdr-date{font-size:10px;color:var(--muted);margin-top:4px;letter-spacing:.12em}
.banner{display:flex;flex-wrap:wrap;gap:8px;justify-content:space-between;align-items:center;
  background:rgba(255,220,100,.04);border:1px solid rgba(255,220,100,.2);border-radius:3px;
  padding:8px 13px;margin-bottom:12px;font-size:9px}
.banner .k{color:var(--yellow);letter-spacing:.1em}
.banner .v{color:#5a6070}.banner .v span{color:var(--yellow)}
.stale .k{color:var(--red)}.stale .v span{color:var(--red)}
.alert-row{display:flex;flex-wrap:wrap;gap:5px;margin-bottom:10px}
.apill{font-size:8px;letter-spacing:.1em;padding:3px 8px;border-radius:2px;border:1px solid;animation:blink 2s ease-in-out infinite}
.aH{color:var(--yellow);border-color:var(--yellow);background:rgba(255,220,100,.07)}
.aX{color:var(--red);border-color:var(--red);background:rgba(255,77,106,.08)}
@keyframes blink{0%,100%{opacity:1}50%{opacity:.45}}
.sec{display:flex;align-items:center;gap:9px;font-size:8px;letter-spacing:.3em;text-transform:uppercase;color:var(--cyan);opacity:.45;margin:16px 0 7px}
.sec::before,.sec::after{content:'';flex:1;height:1px;background:var(--bd)}
.mgrid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:7px}
.mc{background:var(--s1);border:1px solid var(--bd);border-radius:3px;padding:11px 13px}
.mc-top{display:flex;justify-content:space-between;align-items:flex-start}
.mc-lbl{font-size:9px;color:var(--muted);letter-spacing:.1em}
.mc-val{font-size:20px;font-weight:700;color:var(--white);margin-top:2px;font-variant-numeric:tabular-nums}
.mc-val.na{color:var(--muted);font-size:15px}
.mc-ch{font-size:10px;margin-top:2px}
.cp{color:var(--green)}.cn{color:var(--red)}.cu{color:var(--muted)}
.mc-right{text-align:right}
.mc-rl{font-size:7px;color:var(--muted);letter-spacing:.1em;margin-bottom:2px}
.mc-ref{font-size:10px;color:#506070}
.badge{display:inline-block;font-size:9px;letter-spacing:.08em;padding:2px 6px;border-radius:2px;margin-top:3px;border:1px solid}
.b-up-strong{color:var(--green);border-color:var(--green);background:rgba(77,255,145,.07)}
.b-up{color:#7dffb1;border-color:#7dffb1;background:rgba(77,255,145,.04)}
.b-down-strong{color:var(--red);border-color:var(--red);background:rgba(255,77,106,.07)}
.b-down{color:#ff8090;border-color:#ff8090;background:rgba(255,77,106,.04)}
.b-neutral{color:var(--yellow);border-color:var(--yellow);background:rgba(255,220,100,.05)}
.mc-bar{height:3px;border-radius:2px;margin-top:7px;background:rgba(255,255,255,.05);overflow:hidden}
.bu{background:var(--green)}.bd{background:var(--red)}.bn{background:var(--yellow)}
.mc-src{font-size:7px;color:var(--muted);margin-top:4px;opacity:.5}
/* NEWS */
.tabs{display:flex;gap:5px;flex-wrap:wrap;margin-bottom:8px}
.tab{font-size:8px;letter-spacing:.12em;text-transform:uppercase;padding:5px 11px;border-radius:2px;
  cursor:pointer;border:1px solid;transition:all .15s;background:transparent;font-family:var(--font)}
.tab-ai{color:var(--purple);border-color:rgba(184,127,255,.2)}
.tab-tech{color:var(--cyan);border-color:rgba(80,200,255,.2)}
.tab-world{color:var(--yellow);border-color:rgba(255,220,100,.2)}
.tab-pl{color:var(--green);border-color:rgba(77,255,145,.2)}
.tab-models{color:var(--purple);border-color:rgba(184,127,255,.2)}
.tab.on{background:rgba(255,255,255,.05);border-color:currentColor}
.npanel{display:none}.npanel.on{display:block}
.feed-hd{font-size:7px;letter-spacing:.2em;text-transform:uppercase;margin-bottom:5px;opacity:.65;margin-top:12px}
.ni{background:var(--s1);border:1px solid var(--bd);border-radius:3px;padding:9px 11px;margin-bottom:4px;transition:border-color .15s}
.ni:hover{border-color:var(--bd2)}
.ni-t{font-size:11px;color:var(--white);line-height:1.4;margin-bottom:3px}
.ni-t a{color:inherit;text-decoration:none}.ni-t a:hover{color:var(--cyan)}
.ni-d{font-size:9px;color:#4a6070;margin-top:3px;line-height:1.5}
.ni-m{display:flex;justify-content:space-between;font-size:7px;color:var(--muted);margin-top:3px}
.noempty{font-size:9px;color:var(--muted);padding:6px 0;opacity:.45}
/* MODEL LIST */
.mrow{display:flex;justify-content:space-between;align-items:center;padding:7px 11px;margin-bottom:4px;background:var(--s1);border:1px solid var(--bd);border-radius:3px}
.mrow-name{font-size:11px;color:var(--white)}
.mrow-sub{font-size:8px;color:var(--muted);margin-top:2px}
.mrow-right{text-align:right}
/* CTX */
.ctx{background:rgba(0,0,0,.35);border:1px solid rgba(80,200,255,.07);border-radius:3px;padding:12px;font-size:10px;line-height:1.8;color:#88b0c8;white-space:pre;overflow-x:auto;margin-top:7px}
.br{display:flex;gap:6px;flex-wrap:wrap;margin-top:8px;align-items:center}
.btn{font-family:var(--font);font-size:8px;letter-spacing:.15em;text-transform:uppercase;padding:6px 12px;border-radius:2px;cursor:pointer;border:1px solid;text-decoration:none;transition:all .15s;display:inline-block;background:transparent}
.bc{color:var(--cyan);border-color:rgba(80,200,255,.2)}.bc:hover{background:rgba(80,200,255,.09);border-color:var(--cyan)}
.bg{color:var(--green);border-color:rgba(77,255,145,.2)}.bg:hover{background:rgba(77,255,145,.09);border-color:var(--green)}
.by{color:var(--yellow);border-color:rgba(255,220,100,.2)}.by:hover{background:rgba(255,220,100,.07);border-color:var(--yellow)}
#cok{display:none;font-size:8px;color:var(--green);letter-spacing:.1em}
.qbar{display:flex;gap:10px;flex-wrap:wrap;font-size:7px;color:var(--muted);margin-top:6px;letter-spacing:.08em}
.qbar span span{color:var(--cyan)}
.nocache{background:rgba(255,77,106,.07);border:1px solid rgba(255,77,106,.3);border-radius:3px;padding:12px;text-align:center;color:var(--red);font-size:10px;margin-bottom:14px}
.nocache a{color:var(--red)}
.foot{margin-top:24px;text-align:center;font-size:7px;color:#1a2535;letter-spacing:.1em}
</style>
</head>
<body>
<div class="wrap">

<!-- HEADER -->
<div class="hdr">
  <div class="hdr-eye">◈ AI REALITY CHECK TERMINAL ◈</div>
  <div class="hdr-clock" id="clock"><?= $now->format('H:i:s') ?></div>
  <div class="hdr-date"><?= $now->format('l, d F Y') ?> · <?= TIMEZONE ?> · UTC <?= $utc->format('H:i') ?></div>
</div>

<?php if (!$cache): ?>
<div class="nocache">⚠ Brak cache.json — <a href="?refresh">kliknij aby pobrać dane</a></div>
<?php endif; ?>

<!-- BANNER -->
<div class="banner <?= $cacheStale?'stale':'' ?>">
  <span class="k">⚠ KNOWLEDGE DRIFT</span>
  <span class="v">Cutoff: <span><?= KNOWLEDGE_CUTOFF ?></span></span>
  <span class="v">Delta: <span>+<?= $daysOld ?> dni</span></span>
  <?php if ($cache): ?><span class="v">Cache: <span><?= $cacheStale?'⚠ ':'' ?><?= fmtAge($cacheAge) ?></span></span><?php endif; ?>
  <span class="v">Crucix: <span><?= $crucixOn?'● ON':'○ OFF' ?></span></span>
  <span class="v">Unix: <span id="unix"><?= $now->getTimestamp() ?></span></span>
</div>

<!-- ALERTS -->
<?php if (!empty($alerts)): ?>
<div class="alert-row">
  <?php foreach ($alerts as $a): ?>
  <span class="apill <?= $a['label']==='EXTREME'?'aX':'aH' ?>">
    ⚡ <?= $a['asset'] ?> <?= sprintf('%+.1f%%',$a['change']) ?> — <?= $a['label'] ?>
  </span>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- KRYPTO -->
<div class="sec">Kryptowaluty</div>
<div class="mgrid">
<?php foreach ([
  ['BTC/USD',$m['btc']??null,CLAUDE_REF['btc']['value'],'$',0,$m['btc_24h']??null],
  ['ETH/USD',$m['eth']??null,CLAUDE_REF['eth']['value'],'$',0,$m['eth_24h']??null],
] as [$lb,$lv,$rf,$px,$dc,$ch]):
  $d=drift($lv,$rf);$cls=driftClass($d);$bw=$d!==null?min(100,abs($d)):0;$bc=$d===null?'bn':($d>0?'bu':'bd');
?>
<div class="mc">
  <div class="mc-top">
    <div>
      <div class="mc-lbl"><?= $lb ?></div>
      <div class="mc-val <?= $lv===null?'na':'' ?>"><?= $lv!==null?$px.fmtNum($lv,$dc):'N/A' ?></div>
      <?php if ($ch!==null): ?><div class="mc-ch <?= $ch>0?'cp':($ch<0?'cn':'cu') ?>"><?= sprintf('%+.2f%%',$ch) ?> 24h</div><?php endif; ?>
    </div>
    <div class="mc-right">
      <div class="mc-rl">WIEDZA CLAUDE</div>
      <div class="mc-ref"><?= $px.fmtNum($rf,$dc) ?></div>
      <div><span class="badge b-<?= $cls ?>"><?= fmtDrift($d) ?></span></div>
    </div>
  </div>
  <div class="mc-bar"><div class="<?= $bc ?>" style="width:<?= $bw ?>%;height:100%;border-radius:2px"></div></div>
  <?php if (!empty($m['source'])): ?><div class="mc-src">src: <?= htmlspecialchars($m['source']) ?></div><?php endif; ?>
</div>
<?php endforeach; ?>
</div>

<!-- SUROWCE -->
<div class="sec">Surowce</div>
<div class="mgrid">
<?php foreach ([
  ['XAU/USD',$m['gold']??null,CLAUDE_REF['gold']['value'],'$',2],
  ['XAG/USD',$m['silver']??null,27.0,'$',2],
] as [$lb,$lv,$rf,$px,$dc]):
  $d=drift($lv,$rf);$cls=driftClass($d);$bw=$d!==null?min(100,abs($d)):0;$bc=$d===null?'bn':($d>0?'bu':'bd');
?>
<div class="mc">
  <div class="mc-top">
    <div><div class="mc-lbl"><?= $lb ?></div><div class="mc-val <?= $lv===null?'na':'' ?>"><?= $lv!==null?$px.fmtNum($lv,$dc):'N/A' ?></div></div>
    <div class="mc-right">
      <div class="mc-rl">WIEDZA CLAUDE</div><div class="mc-ref"><?= $px.fmtNum($rf,$dc) ?></div>
      <div><span class="badge b-<?= $cls ?>"><?= fmtDrift($d) ?></span></div>
    </div>
  </div>
  <div class="mc-bar"><div class="<?= $bc ?>" style="width:<?= $bw ?>%;height:100%;border-radius:2px"></div></div>
</div>
<?php endforeach; ?>
</div>

<!-- WALUTY -->
<div class="sec">Waluty (baza: USD)</div>
<div class="mgrid">
<?php foreach ([
  ['USD/PLN',$m['usd_pln']??null,CLAUDE_REF['usd_pln']['value'],'',4],
  ['USD/EUR',$m['usd_eur']??null,CLAUDE_REF['usd_eur']['value'],'',4],
  ['USD/GBP',$m['usd_gbp']??null,CLAUDE_REF['usd_gbp']['value'],'',4],
] as [$lb,$lv,$rf,$px,$dc]):
  $d=drift($lv,$rf);$cls=driftClass($d);$bw=$d!==null?min(100,abs($d)):0;$bc=$d===null?'bn':($d>0?'bu':'bd');
?>
<div class="mc">
  <div class="mc-top">
    <div><div class="mc-lbl"><?= $lb ?></div><div class="mc-val <?= $lv===null?'na':'' ?>"><?= $lv!==null?$px.fmtNum($lv,$dc):'N/A' ?></div></div>
    <div class="mc-right">
      <div class="mc-rl">WIEDZA CLAUDE</div><div class="mc-ref"><?= $px.fmtNum($rf,$dc) ?></div>
      <div><span class="badge b-<?= $cls ?>"><?= fmtDrift($d) ?></span></div>
    </div>
  </div>
  <div class="mc-bar"><div class="<?= $bc ?>" style="width:<?= $bw ?>%;height:100%;border-radius:2px"></div></div>
</div>
<?php endforeach; ?>
</div>

<!-- CRUCIX EXTRA -->
<?php if ($crucixOn && (!empty($cx['vix']) || !empty($cx['oil_wti']) || !empty($cx['spy']))): ?>
<div class="sec" style="color:#b87fff;opacity:.45">Crucix · Live Signals</div>
<div class="mgrid">
<?php foreach ([
  ['VIX',$cx['vix']??null,20.0,'',2,null,'Fear Index'],
  ['WTI/USD',$cx['oil_wti']??null,80.0,'$',2,null,'Ropa'],
  ['SPY',$cx['spy']??null,480.0,'$',2,$cx['spy_24h']??null,'S&P 500 ETF'],
] as [$lb,$lv,$rf,$px,$dc,$ch,$hint]):
  if (!$lv) continue;
  $d=drift($lv,$rf);$cls=driftClass($d);$bw=$d!==null?min(100,abs($d)):0;$bc=$d===null?'bn':($d>0?'bu':'bd');
?>
<div class="mc" style="border-color:rgba(184,127,255,.15)">
  <div class="mc-top">
    <div>
      <div class="mc-lbl"><?= $lb ?> <span style="color:#554466;font-size:7px"><?= $hint ?></span></div>
      <div class="mc-val"><?= $px.fmtNum($lv,$dc) ?></div>
      <?php if ($ch!==null): ?><div class="mc-ch <?= $ch>0?'cp':($ch<0?'cn':'cu') ?>"><?= sprintf('%+.2f%%',$ch) ?> 24h</div><?php endif; ?>
    </div>
    <div class="mc-right">
      <div class="mc-rl">REF</div><div class="mc-ref"><?= $px.fmtNum($rf,$dc) ?></div>
      <div><span class="badge b-<?= $cls ?>"><?= fmtDrift($d) ?></span></div>
    </div>
  </div>
  <div class="mc-bar"><div style="width:<?= $bw ?>%;height:100%;border-radius:2px;background:#b87fff"></div></div>
</div>
<?php endforeach; ?>
</div>
<?php if (!empty($cx['acled_count'])): ?>
<div class="mc" style="border-color:rgba(255,77,106,.15);margin-top:7px">
  <div class="mc-top">
    <div>
      <div class="mc-lbl">ACLED · Konflikty</div>
      <div class="mc-val" style="font-size:16px;color:var(--red)"><?= $cx['acled_count'] ?> wydarzeń</div>
      <?php if (!empty($cx['acled_fatalities'])): ?><div class="mc-ch cn"><?= $cx['acled_fatalities'] ?> ofiar</div><?php endif; ?>
    </div>
    <div class="mc-right"><div class="mc-rl" style="color:rgba(255,77,106,.5)">CRUCIX · ACLED</div></div>
  </div>
</div>
<?php endif; ?>
<?php endif; ?>

<!-- NEWSY + MODELE -->
<div class="sec">Newsy & Modele AI</div>

<!-- TABS — jeden zestaw -->
<div class="tabs">
  <?php foreach ($cats as $cat): ?>
  <?php if (empty($byCat[$cat])) continue; ?>
  <button class="tab tab-<?= $cat ?> <?= $cat===$firstCat?'on':'' ?>"
    onclick="showTab('<?= $cat ?>')" id="tab-<?= $cat ?>">
    <?= $catLbls[$cat] ?>
  </button>
  <?php endforeach; ?>
  <?php if (!empty($models)): ?>
  <button class="tab tab-models <?= !$firstCat?'on':'' ?>"
    onclick="showTab('models')    $raw = file_get_contents(CACHE_FILE);
    if ($raw) {
        $cache     = json_decode($raw, true);
        $cacheAge  = time() - ($cache['meta']['generated_unix'] ?? 0);
        $cacheStale= $cacheAge > CACHE_MAX_AGE;
    }
}

$now     = new DateTimeImmutable('now', new DateTimeZone(TIMEZONE));
$utc     = $now->setTimezone(new DateTimeZone('UTC'));
$daysOld = (int)(($now->getTimestamp() - (new DateTimeImmutable(KNOWLEDGE_CUTOFF))->getTimestamp()) / 86400);
$m       = $cache['market'] ?? [];
$news    = $cache['news']   ?? [];
$alerts  = $cache['meta']['alerts']  ?? [];
$quality = $cache['meta']['quality'] ?? [];

// ─── HELPERS ─────────────────────────────────────────────────────────────
function drift(?float $live, float $ref): ?float {
    if (!$live) return null;
    return ($live - $ref) / $ref * 100;
}
function driftClass(?float $p): string {
    if ($p===null) return 'neutral';
    if ($p> 8) return 'up-strong'; if ($p> 1) return 'up';
    if ($p<-8) return 'down-strong'; if ($p<-1) return 'down';
    return 'neutral';
}
function fmtNum(?float $v, int $dec=2): string {
    return $v!==null ? number_format($v,$dec,'.',',' ) : 'N/A';
}
function fmtDrift(?float $p): string { return $p!==null ? sprintf('%+.1f%%',$p) : '—'; }
function fmtAge(int $s): string {
    if ($s<60) return "{$s}s temu";
    if ($s<3600) return floor($s/60)."min temu";
    return floor($s/3600)."h temu";
}
function catColor(string $c): string {
    return ['ai'=>'#b87fff','tech'=>'#50c8ff','world'=>'#ffdc64','pl'=>'#4dff91'][$c]??'#888';
}

// ─── RAW XML ──────────────────────────────────────────────────────────────
if (isset($_GET['xml'])) {
    header('Content-Type: text/plain; charset=utf-8');
    $cg=$cache['meta']['generated']??$utc->format('c');
    echo "<context_snapshot generated=\"{$cg}\" cache_age_sec=\"{$cacheAge}\">\n";
    echo "  <temporal>\n";
    echo "    <utc_iso>{$utc->format('c')}</utc_iso>\n";
    echo "    <unix>{$now->getTimestamp()}</unix>\n";
    echo "    <local_time timezone=\"".TIMEZONE."\">{$now->format('l, d F Y H:i:s T')}</local_time>\n";
    echo "    <model_knowledge_cutoff>".KNOWLEDGE_CUTOFF."</model_knowledge_cutoff>\n";
    echo "    <days_since_cutoff>{$daysOld}</days_since_cutoff>\n";
    echo "  </temporal>\n";
    echo "  <market_live cache_generated=\"{$cg}\">\n";
    foreach (['btc'=>'btc_usd','eth'=>'eth_usd','gold'=>'gold_oz_usd','usd_pln'=>'usd_pln','usd_eur'=>'usd_eur','usd_gbp'=>'usd_gbp'] as $k=>$tag) {
        $v=$m[$k]??null;
        echo "    <{$tag}>".($v!==null?round($v,4):'unavailable')."</{$tag}>\n";
    }
    echo "  </market_live>\n";
    echo "  <model_reference>\n";
    foreach (CLAUDE_REF as $k=>$ref) echo "    <{$k}_ref label=\"{$ref['label']}\">{$ref['value']}</{$k}_ref>\n";
    echo "  </model_reference>\n";
    if (!empty($alerts)) {
        echo "  <volatility_alerts>\n";
        foreach ($alerts as $a) echo "    <alert asset=\"{$a['asset']}\" change=\"".sprintf('%+.1f%%',$a['change'])."\" level=\"{$a['label']}\"/>\n";
        echo "  </volatility_alerts>\n";
    }
    if (!empty($news)) {
        echo "  <news_headlines>\n";
        foreach ($news as $feed) {
            if (empty($feed['items'])) continue;
            echo "    <feed id=\"{$feed['id']}\" label=\"{$feed['label']}\" cat=\"{$feed['cat']}\">\n";
            foreach (array_slice($feed['items'],0,3) as $item) {
                $t=htmlspecialchars($item['title'],ENT_XML1);
                echo "      <item date=\"{$item['date']}\">{$t}</item>\n";
            }
            echo "    </feed>\n";
        }
        echo "  </news_headlines>\n";
    }
    echo "</context_snapshot>\n";
    exit;
}

if (isset($_GET['json'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($cache, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
    exit;
}
?><!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>AI Reality Check Terminal</title>
<style>
@import url('https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500;600;700&display=swap');
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#060810;--s1:rgba(255,255,255,.025);--bd:rgba(80,200,255,.1);--bd2:rgba(80,200,255,.22);
  --cyan:#50c8ff;--green:#4dff91;--red:#ff4d6a;--yellow:#ffdc64;--purple:#b87fff;
  --muted:#445566;--text:#bccfdf;--white:#f0f4f8;--font:'IBM Plex Mono','Courier New',monospace;
}
body{background:var(--bg);color:var(--text);font-family:var(--font);min-height:100vh;padding:18px 12px 60px;overflow-x:hidden;position:relative}
body::before{content:'';position:fixed;inset:0;pointer-events:none;z-index:0;
  background-image:linear-gradient(rgba(80,200,255,.018) 1px,transparent 1px),linear-gradient(90deg,rgba(80,200,255,.018) 1px,transparent 1px);background-size:32px 32px}
body::after{content:'';position:fixed;inset:0;pointer-events:none;z-index:0;
  background:repeating-linear-gradient(0deg,transparent,transparent 2px,rgba(0,0,0,.06) 2px,rgba(0,0,0,.06) 4px)}
.wrap{position:relative;z-index:1;max-width:900px;margin:0 auto}

/* HEADER */
.hdr{text-align:center;margin-bottom:22px}
.hdr-eye{font-size:9px;letter-spacing:.4em;color:var(--cyan);opacity:.55;text-transform:uppercase;margin-bottom:5px}
.hdr-clock{font-size:clamp(36px,9vw,58px);font-weight:700;color:var(--white);letter-spacing:.06em;
  text-shadow:0 0 40px rgba(80,200,255,.3);font-variant-numeric:tabular-nums;line-height:1}
.hdr-date{font-size:10px;color:var(--muted);margin-top:4px;letter-spacing:.12em}

/* BANNER */
.banner{display:flex;flex-wrap:wrap;gap:8px;justify-content:space-between;align-items:center;
  background:rgba(255,220,100,.04);border:1px solid rgba(255,220,100,.2);border-radius:3px;
  padding:8px 13px;margin-bottom:12px;font-size:9px}
.banner .k{color:var(--yellow);letter-spacing:.1em}
.banner .v{color:#5a6070}.banner .v span{color:var(--yellow)}
.stale .k{color:var(--red)}.stale .v span{color:var(--red)}

/* ALERTS */
.alert-row{display:flex;flex-wrap:wrap;gap:5px;margin-bottom:10px}
.apill{font-size:8px;letter-spacing:.1em;padding:3px 8px;border-radius:2px;border:1px solid;animation:blink 2s ease-in-out infinite}
.aH{color:var(--yellow);border-color:var(--yellow);background:rgba(255,220,100,.07)}
.aX{color:var(--red);border-color:var(--red);background:rgba(255,77,106,.08)}
@keyframes blink{0%,100%{opacity:1}50%{opacity:.45}}

/* SECTION */
.sec{display:flex;align-items:center;gap:9px;font-size:8px;letter-spacing:.3em;text-transform:uppercase;
  color:var(--cyan);opacity:.45;margin:16px 0 7px}
.sec::before,.sec::after{content:'';flex:1;height:1px;background:var(--bd)}

/* MARKET */
.mgrid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:7px}
.mc{background:var(--s1);border:1px solid var(--bd);border-radius:3px;padding:11px 13px}
.mc-top{display:flex;justify-content:space-between;align-items:flex-start}
.mc-lbl{font-size:9px;color:var(--muted);letter-spacing:.1em}
.mc-val{font-size:20px;font-weight:700;color:var(--white);margin-top:2px;font-variant-numeric:tabular-nums}
.mc-val.na{color:var(--muted);font-size:15px}
.mc-ch{font-size:10px;margin-top:2px}
.cp{color:var(--green)}.cn{color:var(--red)}.cu{color:var(--muted)}
.mc-right{text-align:right}
.mc-rl{font-size:7px;color:var(--muted);letter-spacing:.1em;margin-bottom:2px}
.mc-ref{font-size:10px;color:#506070}
.badge{display:inline-block;font-size:9px;letter-spacing:.08em;padding:2px 6px;border-radius:2px;margin-top:3px;border:1px solid}
.b-up-strong{color:var(--green);border-color:var(--green);background:rgba(77,255,145,.07)}
.b-up{color:#7dffb1;border-color:#7dffb1;background:rgba(77,255,145,.04)}
.b-down-strong{color:var(--red);border-color:var(--red);background:rgba(255,77,106,.07)}
.b-down{color:#ff8090;border-color:#ff8090;background:rgba(255,77,106,.04)}
.b-neutral{color:var(--yellow);border-color:var(--yellow);background:rgba(255,220,100,.05)}
.mc-bar{height:3px;border-radius:2px;margin-top:7px;background:rgba(255,255,255,.05);overflow:hidden}
.bu{background:var(--green)}.bd{background:var(--red)}.bn{background:var(--yellow)}
.mc-src{font-size:7px;color:var(--muted);margin-top:4px;opacity:.5}

/* NEWS */
.tabs{display:flex;gap:5px;flex-wrap:wrap;margin-bottom:8px}
.tab{font-size:8px;letter-spacing:.12em;text-transform:uppercase;padding:5px 11px;border-radius:2px;
  cursor:pointer;border:1px solid;transition:all .15s;background:transparent;font-family:var(--font)}
.tab-ai{color:var(--purple);border-color:rgba(184,127,255,.2)}
.tab-tech{color:var(--cyan);border-color:rgba(80,200,255,.2)}
.tab-world{color:var(--yellow);border-color:rgba(255,220,100,.2)}
.tab-pl{color:var(--green);border-color:rgba(77,255,145,.2)}
.tab.on{background:rgba(255,255,255,.05);border-color:currentColor}
.npanel{display:none}.npanel.on{display:block}
.feed-hd{font-size:7px;letter-spacing:.2em;text-transform:uppercase;margin-bottom:5px;opacity:.65;margin-top:12px}
.ni{background:var(--s1);border:1px solid var(--bd);border-radius:3px;padding:9px 11px;margin-bottom:4px;transition:border-color .15s}
.ni:hover{border-color:var(--bd2)}
.ni-t{font-size:11px;color:var(--white);line-height:1.4;margin-bottom:3px}
.ni-t a{color:inherit;text-decoration:none}.ni-t a:hover{color:var(--cyan)}
.ni-d{font-size:9px;color:#4a6070;margin-top:3px;line-height:1.5}
.ni-m{display:flex;justify-content:space-between;font-size:7px;color:var(--muted);margin-top:3px}
.noempty{font-size:9px;color:var(--muted);padding:6px 0;opacity:.45}

/* CTX */
.ctx{background:rgba(0,0,0,.35);border:1px solid rgba(80,200,255,.07);border-radius:3px;
  padding:12px;font-size:10px;line-height:1.8;color:#88b0c8;white-space:pre;overflow-x:auto;margin-top:7px}

/* BUTTONS */
.br{display:flex;gap:6px;flex-wrap:wrap;margin-top:8px;align-items:center}
.btn{font-family:var(--font);font-size:8px;letter-spacing:.15em;text-transform:uppercase;
  padding:6px 12px;border-radius:2px;cursor:pointer;border:1px solid;text-decoration:none;
  transition:all .15s;display:inline-block;background:transparent}
.bc{color:var(--cyan);border-color:rgba(80,200,255,.2)}.bc:hover{background:rgba(80,200,255,.09);border-color:var(--cyan)}
.bg{color:var(--green);border-color:rgba(77,255,145,.2)}.bg:hover{background:rgba(77,255,145,.09);border-color:var(--green)}
.by{color:var(--yellow);border-color:rgba(255,220,100,.2)}.by:hover{background:rgba(255,220,100,.07);border-color:var(--yellow)}
#cok{display:none;font-size:8px;color:var(--green);letter-spacing:.1em}
.qbar{display:flex;gap:10px;flex-wrap:wrap;font-size:7px;color:var(--muted);margin-top:6px;letter-spacing:.08em}
.qbar span span{color:var(--cyan)}
.nocache{background:rgba(255,77,106,.07);border:1px solid rgba(255,77,106,.3);border-radius:3px;
  padding:12px;text-align:center;color:var(--red);font-size:10px;margin-bottom:14px}
.nocache a{color:var(--red)}
.foot{margin-top:24px;text-align:center;font-size:7px;color:#1a2535;letter-spacing:.1em}
</style>
</head>
<body>
<div class="wrap">

<div class="hdr">
  <div class="hdr-eye">◈ AI REALITY CHECK TERMINAL ◈</div>
  <div class="hdr-clock" id="clock"><?= $now->format('H:i:s') ?></div>
  <div class="hdr-date"><?= $now->format('l, d F Y') ?> · <?= TIMEZONE ?> · UTC <?= $utc->format('H:i') ?></div>
</div>

<?php if (!$cache): ?>
<div class="nocache">⚠ Brak cache.json — <a href="?refresh">kliknij aby pobrać dane</a> lub skonfiguruj cron.</div>
<?php endif; ?>

<div class="banner <?= $cacheStale?'stale':'' ?>">
  <span class="k">⚠ KNOWLEDGE DRIFT</span>
  <span class="v">Cutoff: <span><?= KNOWLEDGE_CUTOFF ?></span></span>
  <span class="v">Delta: <span>+<?= $daysOld ?> dni</span></span>
  <?php if ($cache): ?><span class="v">Cache: <span><?= $cacheStale?'⚠ ':'' ?><?= fmtAge($cacheAge) ?></span></span><?php endif; ?>
  <span class="v">Unix: <span id="unix"><?= $now->getTimestamp() ?></span></span>
</div>

<?php if (!empty($alerts)): ?>
<div class="alert-row">
  <?php foreach ($alerts as $a): ?>
  <span class="apill <?= $a['label']==='EXTREME'?'aX':'aH' ?>">
    ⚡ <?= $a['asset'] ?> <?= sprintf('%+.1f%%',$a['change']) ?> 24H — <?= $a['label'] ?>
  </span>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- KRYPTO -->
<div class="sec">Kryptowaluty</div>
<div class="mgrid">
<?php foreach ([
  ['BTC/USD',$m['btc']??null,CLAUDE_REF['btc']['value'],'$',0,$m['btc_24h']??null],
  ['ETH/USD',$m['eth']??null,CLAUDE_REF['eth']['value'],'$',0,$m['eth_24h']??null],
] as [$lb,$lv,$rf,$px,$dc,$ch]):
  $d=drift($lv,$rf);$cls=driftClass($d);$bw=$d!==null?min(100,abs($d)):0;$bc=$d===null?'bn':($d>0?'bu':'bd');
?>
<div class="mc">
  <div class="mc-top">
    <div>
      <div class="mc-lbl"><?= $lb ?></div>
      <div class="mc-val <?= $lv===null?'na':'' ?>"><?= $lv!==null?$px.fmtNum($lv,$dc):'N/A' ?></div>
      <?php if ($ch!==null): ?><div class="mc-ch <?= $ch>0?'cp':($ch<0?'cn':'cu') ?>"><?= sprintf('%+.2f%%',$ch) ?> 24h</div><?php endif; ?>
    </div>
    <div class="mc-right">
      <div class="mc-rl">WIEDZA CLAUDE</div>
      <div class="mc-ref"><?= $px.fmtNum($rf,$dc) ?></div>
      <div><span class="badge b-<?= $cls ?>"><?= fmtDrift($d) ?></span></div>
    </div>
  </div>
  <div class="mc-bar"><div class="<?= $bc ?>" style="width:<?= $bw ?>%;height:100%;border-radius:2px"></div></div>
  <?php if (!empty($m['source'])): ?><div class="mc-src">src: <?= htmlspecialchars($m['source']) ?></div><?php endif; ?>
</div>
<?php endforeach; ?>
</div>

<!-- SUROWCE -->
<div class="sec">Surowce</div>
<div class="mgrid">
<?php foreach ([
  ['XAU/USD',$m['gold']??null,CLAUDE_REF['gold']['value'],'$',2],
  ['XAG/USD',$m['silver']??null,27.0,'$',2],
] as [$lb,$lv,$rf,$px,$dc]):
  $d=drift($lv,$rf);$cls=driftClass($d);$bw=$d!==null?min(100,abs($d)):0;$bc=$d===null?'bn':($d>0?'bu':'bd');
?>
<div class="mc">
  <div class="mc-top">
    <div><div class="mc-lbl"><?= $lb ?></div><div class="mc-val <?= $lv===null?'na':'' ?>"><?= $lv!==null?$px.fmtNum($lv,$dc):'N/A' ?></div></div>
    <div class="mc-right">
      <div class="mc-rl">WIEDZA CLAUDE</div><div class="mc-ref"><?= $px.fmtNum($rf,$dc) ?></div>
      <div><span class="badge b-<?= $cls ?>"><?= fmtDrift($d) ?></span></div>
    </div>
  </div>
  <div class="mc-bar"><div class="<?= $bc ?>" style="width:<?= $bw ?>%;height:100%;border-radius:2px"></div></div>
</div>
<?php endforeach; ?>
</div>

<!-- WALUTY -->
<div class="sec">Waluty (baza: USD)</div>
<div class="mgrid">
<?php foreach ([
  ['USD/PLN',$m['usd_pln']??null,CLAUDE_REF['usd_pln']['value'],'',4],
  ['USD/EUR',$m['usd_eur']??null,CLAUDE_REF['usd_eur']['value'],'',4],
  ['USD/GBP',$m['usd_gbp']??null,CLAUDE_REF['usd_gbp']['value'],'',4],
] as [$lb,$lv,$rf,$px,$dc]):
  $d=drift($lv,$rf);$cls=driftClass($d);$bw=$d!==null?min(100,abs($d)):0;$bc=$d===null?'bn':($d>0?'bu':'bd');
?>
<div class="mc">
  <div class="mc-top">
    <div><div class="mc-lbl"><?= $lb ?></div><div class="mc-val <?= $lv===null?'na':'' ?>"><?= $lv!==null?$px.fmtNum($lv,$dc):'N/A' ?></div></div>
    <div class="mc-right">
      <div class="mc-rl">WIEDZA CLAUDE</div><div class="mc-ref"><?= $px.fmtNum($rf,$dc) ?></div>
      <div><span class="badge b-<?= $cls ?>"><?= fmtDrift($d) ?></span></div>
    </div>
  </div>
  <div class="mc-bar"><div class="<?= $bc ?>" style="width:<?= $bw ?>%;height:100%;border-radius:2px"></div></div>
</div>
<?php endforeach; ?>
</div>

<!-- NEWS -->
<?php
$cats=['ai','tech','world','pl'];
$catLbls=['ai'=>'AI / TECH','tech'=>'TECH','world'=>'ŚWIAT','pl'=>'POLSKA'];
$byCat=[];
foreach ($news as $feed) $byCat[$feed['cat']][]=$feed;
$firstCat='';
foreach ($cats as $c) { if (!empty($byCat[$c])) { $firstCat=$c; break; } }
?>
<div class="sec">Newsy</div>
<div class="tabs">
<?php foreach ($cats as $cat): ?>
<?php if (empty($byCat[$cat])) continue; ?>
<button class="tab tab-<?= $cat ?> <?= $cat===$firstCat?'on':'' ?>"
  onclick="showTab('<?= $cat ?>')" id="tab-<?= $cat ?>">
  <?= $catLbls[$cat] ?>
</button>
<?php endforeach; ?>
</div>

<?php foreach ($cats as $cat): ?>
<?php if (empty($byCat[$cat])) continue; ?>
<div class="npanel <?= $cat===$firstCat?'on':'' ?>" id="np-<?= $cat ?>">
<?php foreach ($byCat[$cat] as $feed): ?>
  <div class="feed-hd" style="color:<?= catColor($cat) ?>"><?= htmlspecialchars($feed['label']) ?></div>
  <?php if (empty($feed['items'])): ?>
  <div class="noempty">Feed niedostępny</div>
  <?php else: ?>
  <?php foreach ($feed['items'] as $item): ?>
  <div class="ni">
    <div class="ni-t"><a href="<?= htmlspecialchars($item['link']) ?>" target="_blank" rel="noopener"><?= htmlspecialchars($item['title']) ?></a></div>
    <?php if ($item['desc']): ?><div class="ni-d"><?= htmlspecialchars($item['desc']) ?></div><?php endif; ?>
    <div class="ni-m"><span><?= htmlspecialchars($feed['label']) ?></span><span><?= $item['date'] ?></span></div>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>
<?php endforeach; ?>
</div>
<?php endforeach; ?>

<?php if (!empty($quality)): ?>
<div class="qbar">
  <span>ŹRÓDŁA: <span><?= implode(', ',$quality['market_sources']??[]) ?></span></span>
  <span>FEEDS OK: <span><?= ($quality['news_feeds_ok']??0).'/'.(($quality['news_feeds_total']??0)) ?></span></span>
  <?php if ($cacheAge!==null): ?><span>CACHE: <span><?= fmtAge($cacheAge) ?></span></span><?php endif; ?>
</div>
<?php endif; ?>

<!-- XML CONTEXT BLOCK -->
<div class="sec">Context Block XML</div>
<div class="br">
  <button class="btn bg" onclick="copyCtx()">⊕ KOPIUJ XML</button>
  <a class="btn bc" href="?xml"  target="_blank">↗ RAW XML</a>
  <a class="btn bc" href="?json" target="_blank">↗ RAW JSON</a>
  <a class="btn by" href="?refresh">↺ FORCE REFRESH</a>
  <span id="cok">✓ SKOPIOWANO</span>
</div>
<pre class="ctx" id="ctx"><?php
$cg=$cache['meta']['generated']??$utc->format('c');
$ls=[
  '<context_snapshot generated="'.$cg.'" cache_age_sec="'.($cacheAge??0).'">',
  '  <temporal>',
  '    <utc_iso>'.$utc->format('c').'</utc_iso>',
  '    <unix>'.$now->getTimestamp().'</unix>',
  '    <local_time timezone="'.TIMEZONE.'">'.$now->format('l, d F Y H:i:s T').'</local_time>',
  '    <model_knowledge_cutoff>'.KNOWLEDGE_CUTOFF.'</model_knowledge_cutoff>',
  '    <days_since_cutoff>'.$daysOld.'</days_since_cutoff>',
  '  </temporal>',
  '  <market_live>',
];
foreach (['btc'=>'btc_usd','eth'=>'eth_usd','gold'=>'gold_oz_usd','usd_pln'=>'usd_pln','usd_eur'=>'usd_eur'] as $k=>$tag) {
  $v=$m[$k]??null;
  $ls[]='    <'.$tag.'>'.($v!==null?round($v,4):'unavailable').'</'.$tag.'>';
}
$ls[]='  </market_live>';
$ls[]='  <model_reference>';
foreach (CLAUDE_REF as $k=>$ref) $ls[]='    <'.$k.'_ref label="'.$ref['label'].'">'.$ref['value'].'</'.$k.'_ref>';
$ls[]='  </model_reference>';
if (!empty($alerts)) {
  $ls[]='  <alerts>';
  foreach ($alerts as $a) $ls[]='    <alert asset="'.$a['asset'].'" change="'.sprintf('%+.1f%%',$a['change']).'" level="'.$a['label'].'"/>';
  $ls[]='  </alerts>';
}
$ls[]='</context_snapshot>';
echo htmlspecialchars(implode("\n",$ls));
?></pre>

<div class="foot">CoinGecko · Binance · Frankfurter ECB · metals.live · RSS · <?= $now->format('Y-m-d H:i:s T') ?></div>
</div>

<script>
(function(){
  var el=document.getElementById('clock'),ux=document.getElementById('unix');
  setInterval(function(){
    var d=new Date();
    el.textContent=d.toLocaleTimeString('pl-PL',{timeZone:'<?= TIMEZONE ?>',hour:'2-digit',minute:'2-digit',second:'2-digit',hour12:false});
    ux.textContent=Math.floor(Date.now()/1000);
  },1000);
})();
function showTab(cat){
  document.querySelectorAll('.tab').forEach(function(t){t.classList.remove('on')});
  document.querySelectorAll('.npanel').forEach(function(p){p.classList.remove('on')});
  var t=document.getElementById('tab-'+cat),p=document.getElementById('np-'+cat);
  if(t)t.classList.add('on');if(p)p.classList.add('on');
}
function copyCtx(){
  navigator.clipboard.writeText(document.getElementById('ctx').textContent).then(function(){
    var ok=document.getElementById('cok');ok.style.display='inline';
    setTimeout(function(){ok.style.display='none'},2500);
  });
}
</script>
</body>
</html>
