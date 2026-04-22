# Reality-check-
knowledge gap checker

Repository: killaseo/ai-reality-check

Key components:

✅ ai.php — Dashboard UI with live market data (crypto, forex, metals) + news feeds
✅ fetch_cache.php — Cron worker that aggregates data every 5 minutes
✅ MIT License — Open source
✅ README.md — Full docs (Polish + English)
✅ Cron setup guide — Step-by-step for cPanel/DirectAdmin
Core functionality:

Measures knowledge drift: Shows days since Claude's 2025-08-31 cutoff
Real-time market data from CoinGecko, Binance, Frankfurter, metals.live
RSS news aggregator (AI, tech, world, Poland categories)
Volatility alerts (>5% 24h change)
XML context block for LLM agents
JSON + XML export endpoints
Auto-refresh with manual override
The project tracks how outdated Claude's knowledge becomes relative to current market conditions and news — perfect for grounding LLM responses in present-day reality.
