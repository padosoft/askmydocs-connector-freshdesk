# Changelog

## [Unreleased]

- Interruttori per installazione `ingestion.enabled`, `chat_tools.enabled` e `case_studies.enabled`, esposti nello schema delle impostazioni per la UI AskMyDocs; flag globali dell'host ancora prioritari.
- Disattivazione dell'ingest con blocco degli avvii e delle richieste successive, sospensione dei lotti e ripresa dal checkpoint; live indipendente dall'ingest, con catalogo ed esecuzione aggiornati alle preferenze correnti.
- Sospensione e ripresa delle generazioni di schede senza ripetere i segmenti completati; blocco delle schede disabilitate all'indicizzazione e alla consultazione, conservando i documenti locali.
- Integrazione UI AskMyDocs: modifica per connessione, esclusione da Sync all, blocco dell'import storico e stato `paused` riprendibile.
- Schede problema → causa → intervento → esito per ticket nuovi o modificati, come documenti locali separati collegati alle citazioni originali; nessuna generazione automatica per il backlog invariato.
- Contratto di generazione fornito dall'host, snapshot redatti, controlli letterali delle evidenze e checkpoint per riprendere i job; invalidazione per revisioni e policy cambiate e cancellazioni confermate.
- Integrazione AskMyDocsDev con OpenRouter/Jev, chunk unitario della scheda, provenienza automatica e recupero delle citazioni originali per motivare le risposte.
- Blocco del trasporto HTTP per qualsiasi metodo diverso da GET: ingest, strumenti live, verifica credenziali e download restano di sola lettura verso Freshdesk.

- Profilo d’uso JSON versione 1, scoperto tramite `extra.askmydocs.agent_usage`, con routing live/indice/combinato, workflow e regole di dominio per l’orchestratore.
- Regole Freshdesk trasferite dal planner host al pacchetto: identità, richiedenti e assegnatari, storico, paginazione, fallimenti e aggiornamento dei dati.

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
