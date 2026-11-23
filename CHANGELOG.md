# Changelog – Bombora Wine Nutrition

## [2.0.0] – 2026-11-23
- Přejmenováno na **Bombora Wine Nutrition**; složka `bombora-wine-nutrition` (dříve `wine-nutrition-manager`), hlavní soubor `bombora-wine-nutrition.php`. Přechod provede Bombora Manager 1.1+, data (tabulky `wine_nutrition*`, nastavení, QR v uploads) zůstávají.
- Menu přesunuto pod **Bombora** (pokud je nainstalovaný Manager), adresa `admin.php?page=wine-nutrition` beze změny.
- Opraveno: do balíčku patří knihovna `vendor/chillerlan` (QR kódy). Release 1.6.0 ji neobsahoval (editor pluginů WordPressu složku vendor nezobrazuje) – na degeorgie.fr se po aktualizaci negenerovaly nové QR kódy.
- Pojistka proti současnému načtení dvou kopií pluginu. Text domain `wine-nutrition-manager` beze změny.

## [1.6.0] – 2026-11-23
- První verze v repozitáři bombora-tech/bombora-wine-nutrition (zdroj: produkční instalace uitgeorgie.nl).
- Přidána hlavička Update URI – aktualizace spravuje Bombora Manager přes GitHub Releases (ověření SHA-256).
