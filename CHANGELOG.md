# Changelog

## [1.0.0] — 2026-10-09

- Prima versione installabile da GitHub tramite Composer con vincolo `^1.0`.
- Identità SVG editoriale nelle schede del connettore e logo Freshdesk nel README, serviti localmente.
- Connettore `freshdesk` con modulo credenziali, vault cifrato e impostazioni per installazione.
- Importazione riprendibile di ticket, conversazioni, note private, articoli pubblicati e allegati compatibili.
- Sincronizzazione ordinaria con finestra dei ticket configurabile (default 90 giorni) e watermark persistente; `syncFull` e `syncIncremental` usano entrambi questo avvio, con punto di ripresa gestito dal pacchetto anziché dal parametro `$since`.
- Recupero storico una tantum tramite `SyncManager::start($installation, history: true)`, dal 1970 senza modificare la finestra ordinaria; copre lo storico enumerabile dalle API, con esclusione dei ticket archiviati accessibili soltanto tramite ID noto.
- Checkpoint persistenti, impronte dei contenuti, lock e gestione del limite di paginazione per entrambe le modalità; articoli pubblicati enumerati senza la finestra temporale dei ticket.
- Nove strumenti live di sola lettura con nomi per installazione e provenienza: `list_tickets`, `search_tickets`, `get_ticket`, `list_conversations`, `search_contacts`, `get_contact`, `search_agents`, `search_articles` e `get_article`.
- Entry point asincrono per scheduler, gestione dei rate limit e download senza inoltro della API key.
- Testbench, PHPStan, Pint, configurazione pubblicabile e README illustrato.

[1.0.0]: https://github.com/padosoft/askmydocs-connector-freshdesk/releases/tag/v1.0.0
