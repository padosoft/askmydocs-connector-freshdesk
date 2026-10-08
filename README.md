<p align="center">
  <img src="docs/readme/logo.svg" width="80" alt="Marchio del connettore: una cuffia per il supporto">
</p>
<p align="center">
  <img src="docs/readme/header.png" width="720" alt="Il robot trasforma un ticket e una guida in documenti consultabili nella knowledge base">
</p>

# AskMyDocs Connector Freshdesk

Ticket, conversazioni e articoli Freshdesk diventano conoscenza per AskMyDocs; sei strumenti di sola lettura permettono alla chat di consultare anche i dati aggiornati.

Il pacchetto Composer è `padosoft/askmydocs-connector-freshdesk`, con namespace `Padosoft\AskMyDocsConnectorFreshdesk` e chiave di registro `freshdesk`. Richiede PHP **8.3+**, Laravel **12 o 13**, `ext-curl`, `ext-fileinfo`, `ext-zip` e `padosoft/askmydocs-connector-base ^1.6`. Licenza [Apache-2.0](LICENSE). Questa prima versione è disponibile localmente: non è stata pubblicata su Packagist.

## Dal supporto alla knowledge base

![Il robot raccoglie ticket, conversazioni e allegati nello stesso libro](docs/readme/scene-concept.png)

La prima importazione comprende i ticket aggiornati negli ultimi **90 giorni**, la descrizione, le conversazioni pubbliche e le note private. La finestra è modificabile; le note private sono inizialmente incluse e possono essere disattivate. Gli articoli pubblicati vengono enumerati per categorie e cartelle, comprese quelle annidate, nella lingua predefinita dell'account.

Gli allegati di ticket e conversazioni diventano documenti separati: PDF, DOCX, TXT e Markdown. Le immagini PNG, JPEG, TIFF e WebP richiedono l'OCR abilitato nell'app. I limiti predefiniti sono **25 MiB per file** e **20 allegati per ticket**, modificabili nelle impostazioni dell'installazione. Dimensione effettiva, MIME e struttura DOCX vengono controllati dopo il download.

## Installare e collegare un account

In AskMyDocsDev il pacchetto è affiancato agli altri in `/Users/marco/packages/askmydocs-connector-freshdesk`. Dalla cartella dell'app:

```bash
php scripts/install-freshdesk-local.php
COMPOSER=composer.local.json composer update padosoft/askmydocs-connector-freshdesk --no-interaction
php artisan vendor:publish --tag=connector-freshdesk-assets
php artisan migrate --path=/Users/marco/packages/askmydocs-connector-freshdesk/database/migrations --realpath
npm run build
```

Il manifest locale e il suo lockfile sono esclusi dai commit. Composer crea un symlink verso il pacchetto; il manifest condiviso dell'app rimane invariato. Il provider Laravel viene scoperto automaticamente e aggiunge il connettore al registro anche quando il lockfile condiviso non lo contiene.

Aprire [AskMyDocsDev su Herd](https://askmydocsdev.test), scegliere **Connectors → Freshdesk**, inserire dominio `azienda.freshdesk.com`, API key, etichetta dell'account e progetto. Il modulo verifica `/api/v2/agents/me` prima di salvare la chiave nel vault cifrato. **Sync now** accoda l'importazione; le impostazioni espongono la finestra, le note private e i limiti degli allegati. Gli account dello stesso tenant possono avere installazioni distinte.

Le API REST v2 di Freshdesk richiedono [Basic Auth con API key](https://developers.freshdesk.com/api/#authentication). OAuth per accedere a queste API non è disponibile: [Freshworks lo ha chiarito](https://community.freshworks.dev/t/how-to-use-oauth-authentication-mechanism-for-freshdesk-api/1691). Gli esempi OAuth delle app Marketplace autorizzano servizi esterni, come Google Sheets, e non sostituiscono questa autenticazione. Verifica delle fonti: 8 ottobre 2026.

Per un altro host Laravel, aggiungere un repository Composer `path` verso questa cartella, con `options.symlink=true` e `options.versions.padosoft/askmydocs-connector-freshdesk=dev-main`, quindi richiedere `dev-main`. Il repository VCS di `askmydocs-connector-base` deve essere configurato anche nell'host finché la dipendenza non è su Packagist. L'host deve fornire `ConnectorIngestionContract`, il contesto tenant, il vault e la gestione delle installazioni. L'adattatore chat e le azioni amministrative sono parte dell'integrazione AskMyDocsDev, non route aggiunte dal pacchetto.

## Importare a lotti e riprendere

![Il robot marca il punto di ripresa tra lotti di documenti sul nastro](docs/readme/scene-workflow.png)

`SyncManager` conserva lo stato nelle tabelle `freshdesk_sync_runs` e `freshdesk_source_states`, isolate per tenant e installazione. `ProcessSyncBatch` lavora a lotti con un lock atomico per installazione. Identificativi remoti, percorsi stabili e impronte dei contenuti evitano di consegnare nuovamente documenti invariati. L'app applica l'upsert dei documenti per percorso durante l'ingestione: anche una ripetizione dopo un arresto tra dispatch e checkpoint conserva l'identità del documento.

Il checkpoint salva fase, pagina e ticket o articoli ancora da elaborare. Il watermark temporale viene consolidato dopo il completamento dell'intera scansione, prendendo l'ora d'inizio come limite superiore dei ticket. I passaggi successivi sovrappongono 120 secondi al watermark completato. Al limite delle 300 pagine, una scansione ordinata per `updated_at` riparte dall'ultimo timestamp meno un secondo; se non riesce ad avanzare, mostra un errore e resta incompleta.

**Prendi tutto** richiede `SyncManager::start($installation, history: true)`: parte dallo storico enumerabile, conserva il periodo ordinario e restituisce il recupero già attivo a una seconda richiesta. Un recupero fallito può riprendere dal suo checkpoint. Il registro generico delle azioni dell'app espone stato, conteggi ed errore; i conteggi riguardano i documenti consegnati alla pipeline, che completa estrazione e indicizzazione in coda.

I ticket non vengono rimossi quando escono dalla finestra. Una cancellazione viene applicata quando il filtro Freshdesk `deleted` la conferma. Per un articolo scomparso dall'elenco viene richiesto il dettaglio: `404` conferma la rimozione; un articolo passato a bozza viene rimosso. Un `404` di ticket non basta, perché può trattarsi di un ticket archiviato. Disattivare le note private provoca, alla sincronizzazione successiva, anche l'aggiornamento dei ticket storici già importati.

Freshdesk non enumera globalmente i ticket archiviati accessibili soltanto con un ID noto: **Prendi tutto** copre lo storico scopribile dalle API, non un export completo dell'account. Documentazione di riferimento: [API REST v2 Freshdesk](https://developers.freshdesk.com/api/).

## Consultare i dati in tempo reale

![Il robot affianca la ricerca nel libro alla consultazione di un ticket con la lente](docs/readme/scene-outcome.png)

`Tools\FreshdeskTools` offre catalogo ed esecutore indipendenti. Ogni nome contiene l'ID dell'installazione, per esempio `freshdesk_42_get_ticket`. Il catalogo richiede un progetto esplicito e contiene soltanto installazioni attive del tenant corrente associate a quel progetto. L'esecuzione ripete queste verifiche e rifiuta gli argomenti sconosciuti.

| Suffisso dello strumento | Argomenti | Operazione |
| --- | --- | --- |
| `list_tickets` | `updated_since?`, `page?`, `per_page?` | Ticket aggiornati; default 90 giorni, massimo 100 per pagina |
| `search_tickets` | `query`, `page?` | Filtro strutturato Freshdesk, per esempio `status:2`; massimo 10 pagine |
| `get_ticket` | `id` | Dettaglio di un ticket |
| `list_conversations` | `id`, `page?` | Conversazioni; rispetta l'impostazione delle note private |
| `search_articles` | `term` | Ricerca per parola chiave tra articoli pubblicati |
| `get_article` | `id` | Dettaglio di un articolo pubblicato |

```php
use Padosoft\AskMyDocsConnectorFreshdesk\Tools\FreshdeskTools;

// Il middleware o il job dell'host ha già attivato il tenant corretto.
$tools = app(FreshdeskTools::class);
$catalog = $tools->catalog('support');
$result = $tools->execute('freshdesk_42_get_ticket', ['id' => 123], 'support');
// result: data/error, physical_request_count e provenance.
```

Nell'app, `FreshdeskChatToolSource` implementa `ChatToolSourceContract`; la chat agentica usa le fonti API e registra richieste fisiche, retry, errori e provenienza. Queste esecuzioni hanno `api_route_id=null`. I contenuti delle risposte vengono convertiti in Markdown e limitati; i metadati degli allegati non espongono gli URL firmati. Gli strumenti sono disponibili nella chat autenticata, mentre il widget pubblico resta fuori da questa versione.

## Configurazione e code

```bash
php artisan vendor:publish --tag=connector-freshdesk-config
php artisan queue:work --queue=connectors,default --timeout=600 --tries=3
```

Usare una coda asincrona e un cache store con lock atomici, per esempio Redis. La connessione della coda deve avere `retry_after > 600` secondi. `StartSync` è l'entry point per lo scheduler dell'host; accoda o riprende la scansione senza segnare conclusa l'importazione. I lotti usano la coda dedicata `freshdesk`, modificabile con `CONNECTOR_FRESHDESK_QUEUE`; `CONNECTOR_FRESHDESK_CONNECTION` sceglie la connessione. AskMyDocsDev registra una connessione Redis `freshdesk` con prenotazione di 660 secondi. Avviare `php artisan queue:work freshdesk --queue=freshdesk --timeout=600 --tries=3` oltre al normale worker `connectors`. Un timeout definitivo lascia un errore riprendibile, conservando il checkpoint.

| Configurazione | Default | Effetto |
| --- | --- | --- |
| Installazione: `date_window_days` | `90` | Periodo ordinario, da 1 a 36500 giorni |
| Installazione: `include_private_notes` | `true` | Note private nel RAG e nelle conversazioni live |
| Installazione: `attachments.enabled` | `true` | Scarica allegati compatibili |
| Installazione: `attachments.max_size_mb` | `25` | Limite effettivo, da 1 a 100 MiB |
| Installazione: `attachments.max_per_ticket` | `20` | Massimo da 1 a 100 file compatibili |
| Pacchetto: `http.timeout` / `connect_timeout` | `15` / `5` s | Timeout API |
| Pacchetto: `http.attempts` | `3` | Tentativi per problemi di connessione e risposte 5xx |
| Pacchetto: `sync.batch_items` / `batch_seconds` | `5` / `60` | Passaggi e durata indicativa del lotto; il singolo ticket può durare di più |
| Pacchetto: `chat_tools.max_result_bytes` | `65536` | Dimensione massima della risposta live |

I `401` e `403` vengono riportati senza ripetere la richiesta. I `429` mantengono il checkpoint e rinviano il lotto per i secondi indicati da `Retry-After`; nella chat vengono restituiti come errore, senza attesa bloccante. Gli errori omettono corpi upstream e credenziali.

I domini devono essere HTTPS `*.freshdesk.com`, senza porte personalizzate o credenziali nell'URL. I download passano da un'allowlist di host, controlli DNS su indirizzi pubblici e pinning dell'indirizzo; ogni redirect viene rivalidato. La API key non viene inoltrata agli allegati, neppure sul dominio dell'account. Gli URL firmati non sono conservati nei metadati. Modificare `attachments.allowed_hosts` soltanto per CDN effettivamente usati dal proprio account; `http.resolve_dns=false` è riservato ai test simulati.

## Sviluppo e verifiche

```bash
composer install
composer test
composer analyse
vendor/bin/pint --test
```

Per sviluppare usando il connector-base già installato nell'app, un `composer.local.json` ignorato può aggiungere un repository `path` verso `AskMyDocsDev/vendor/padosoft/askmydocs-connector-base`, con versione locale `1.6.0`. In quel caso usare `COMPOSER=composer.local.json composer install`.

La suite usa Testbench, SQLite e HTTP simulato. Copre verifica e modifica delle credenziali, isolamento, note private, articoli annidati, download e OCR, limiti, retry, paginazione, ripresa, cancellazioni e assenza di nuove consegne per contenuti invariati. La matrice CI prevede Laravel 12/13 e PHP 8.3/8.4. La validazione effettuata localmente è su Laravel 13 e PHP 8.4; il collaudo con un account reale richiede dominio e API key nel modulo credenziali.

Le illustrazioni sono asset originali; il marchio del pacchetto non è il logo ufficiale Freshdesk. [Prompt e provenienza delle illustrazioni](docs/readme/prompts.md) · [Changelog](CHANGELOG.md).
