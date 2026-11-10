# Si është implementuar WooCommerce Demo Mode

Ky dokument shpjegon ndërtimin teknik të Demo Mode. Për përdorimin hap pas hapi, shih [woocommerce-demo-mode.md](woocommerce-demo-mode.md).

> Integrimi është testuar vetëm me dyqane të simuluara dhe përgjigje API të imituara. Nuk është verifikuar me një dyqan real WooCommerce. Edhe rruga "Real Gemini" është testuar vetëm me një gjenerues të imituar.

## Ideja kryesore

Demo Mode nuk është një implementim i dytë i WooCommerce. Importi, krahasimi i fushave, zbulimi i konflikteve, sinkronizimi, historiku dhe rollback-u janë i njëjti kod për dyqanet reale dhe për demon.

Ndryshon vetëm shtresa e fundit, ajo që merr dhe dërgon të dhënat:

```text
Controllers (Import, Sync, Demo)
        |
Shërbimet e përbashkëta
(WooCommerceProductImporter, WooCommerceSyncPreviewService,
 WooCommerceSyncService, CapabilityDetector, WooCommerceConnectionService)
        |
WooCommerceService  <-- zgjedh transportin dhe zbaton allowlist-ën
        |
        +-- RealWooCommerceTransport --> HTTPS --> dyqani real
        |
        +-- DemoWooCommerceTransport --> tabela woocommerce_demo_products
```

## Kufiri i transportit

Interfejsi `WooCommerceTransport` ka pesë metoda: `listProducts`, `getProduct`, `updateProduct`, `systemStatus` dhe `siteIndex`.

| Klasa | Roli |
| --- | --- |
| `RealWooCommerceTransport` | Kodi HTTP që më parë ishte te `WooCommerceService`: mbrojtja nga SSRF, DNS pinning, kufizimi i kërkesave dhe riprovimet. |
| `DemoWooCommerceTransport` | Përgjigjet nga databaza dhe nuk ka klient HTTP. Prandaj një dyqan demo nuk mund të arrijë rrjetin. |
| `WooCommerceService` | E vetmja hyrje. `transportFor()` refuzon dyqanet e shkëputura dhe zgjedh transportin sipas `$store->isDemo()`. |

Lista `WooCommerceService::WRITABLE_FIELDS` lejon vetëm `name`, `description`, `short_description` dhe `slug`. Ajo zbatohet në shërbim për të gjitha dyqanet, dhe transporti demo e zbaton edhe një herë vetë.

Përkthimi nga statusi HTTP te gabimi i aplikacionit u zhvendos te `WooCommerceError::fromHttpStatus()`, që të dy transportet të prodhojnë të njëjtët gabime.

## Ndryshimet në databazë

Të gjitha janë shtuese, në migrimin `2026_11_09_700000_add_woocommerce_demo_mode.php`.

| Tabela | Ndryshimi | Pse |
| --- | --- | --- |
| `woocommerce_stores` | `connection_type` (parazgjedhja `live`) | Identifikon qartë dyqanet `demo`. |
| `woocommerce_stores` | `simulated_outage` | Aktivizon ndërprerjen e simuluar. |
| `products` | `demo_store_id` (nullable, FK) | Shënon produktet lokale të krijuara nga një import demo. |
| `woocommerce_demo_products` | tabelë e re | Mban anën "remote" të dyqanit demo, me `payload` JSON në formatin e WooCommerce. |

`woocommerce_demo_products` ka çelës unik `(woocommerce_store_id, remote_id)`, prandaj çdo dyqan demo ka kopjen e vet të katalogut.

## Aktivizimi i dyqanit

`WooCommerceDemoService::activate()` krijon dyqanin me `createOrFirst`, duke përdorur adresën `https://demo-store.invalid`.

- Çelësi unik `(user_id, url)` garanton një dyqan demo për përdorues, edhe me kërkesa të njëkohshme.
- Domeni `.invalid` refuzohet nga `StoreUrlGuard`, prandaj një dyqan real nuk mund ta përdorë këtë adresë.
- Kredencialet mbeten `null`. Për këtë arsye `isDisconnected()` nuk i kontrollon ato për dyqanet demo.
- Katalogu mbushet vetëm herën e parë, pastaj ekzekutohet testi normal i lidhjes.

`WooCommerceDemoController` kontrollon pronësinë me policy dhe kthen 404 nëse dyqani nuk është demo. Faqet e redaktimit dhe shkëputjes në `WooCommerceStoreController` kthejnë 404 për dyqanet demo.

## Katalogu dhe transporti demo

`DemoCatalog` përmban 14 regjistrime fiktive, me ID nga 900101 dhe data fikse:

- 13 produkte të vlefshme, me raste si përshkrime të munguara ose të dobëta, emra të përsëritur, SKU të munguar, statuse të ndryshme, një produkt `variable` dhe një `grouped`.
- 1 regjistrim pa emër, që importi normal e raporton si gabim.

Transporti sillet kështu:

| Veprimi | Sjellja |
| --- | --- |
| Listimi | Renditje sipas ID-së, maksimumi 7 për faqe (`PAGE_SIZE`), pra importi lexon 2 faqe. |
| Leximi | Kthen `payload`. ID e panjohur jep 404, që bëhet `NotFound`. |
| Përditësimi | Në transaksion me `lockForUpdate`. Ndryshon vetëm fushat e lejuara dhe `date_modified_gmt`. |
| Shkrim pa fusha të lejuara | Refuzohet si `Rejected`. |
| `simulated_outage` | Çdo kërkesë përgjigjet si HTTP 503, që bëhet `Unavailable`. |
| Dyqan jo-demo | Hedh `LogicException`, sepse transporti shërben vetëm dyqane demo. |

`systemStatus` raporton Yoast SEO, kështu që faqja tregon "SEO Sync Not Supported" si te një dyqan real.

## Importi

Importi përdor `WooCommerceProductImporter` me vetëm këto dallime për dyqanet demo:

- Nuk kërkohen përputhje SKU me produktet reale.
- Produkti lokal merr `demo_store_id` dhe nuk merr SKU lokal. SKU i dyqanit ruhet te `woocommerce_product_links`.
- Gjuha detyrohet në anglisht, sepse katalogu është në anglisht.
- `WooCommerceImportController` i lexon të gjitha faqet gjatë kërkesës, kështu që nuk nevojitet queue worker.

## Përmbajtja AI

`WooCommerceDemoContentService` i vendos draftet në radhën normale të Content Review, duke krijuar një `BulkGenerationBatch` dhe një `BulkGenerationItem` për secilin.

**Sample AI Output** (`generateSamples`)

- Teksti ndërtohet nga shabllone fikse sipas kategorisë, pra i njëjti produkt merr gjithmonë të njëjtin rezultat.
- Krijohet një `AiGeneration` me `provider = sample` dhe 0 tokena.
- Produktet read-only dhe ato me draft në pritje anashkalohen.

**Real Gemini** (`generateWithGemini`)

- Thirret vetëm nga `realContent` në controller, pasi të jetë pranuar `confirm_real_ai`.
- Përdor `ProductContentGenerator` ekzistues, me kuotën, bllokimin dhe gjurmimin e tokenave.
- Kufiri për minutë përdor të njëjtin numërues si `throttle:ai-generate`, por kontrollohet në controller që përdoruesi të shohë një faqe normale në vend të JSON.

Sample output nuk konsiderohet gjenerim real. `User::aiGenerations()` dhe `GenerationQuota` përdorin scope-in `real()`, prandaj ai nuk shfaqet në Generation History dhe nuk konsumon kuotë.

Produktet demo nuk arrijnë te Gemini nga rrugë të tjera:

| Vendi | Mbrojtja |
| --- | --- |
| `GenerationController::store` | Kthen 422 me kodin `demo_product`. |
| `AiStudioController` | Ridrejton te faqja e demos. |
| Bulk generation | Përdor `$user->products()`, që i përjashton. |
| `products/show` | Nuk shfaq panelin AI. |

## Rishikimi dhe aprovimi

Përdoret `ContentReviewController` dhe `ContentApprovalService` ekzistues. U shtuan:

- `ContentSource::Sample`. `ProductContentGenerator::save` e përdor kur gjenerimi ka `provider = sample`, që teksti të mos paraqitet si AI real edhe pas aprovimit. Ai lejohet për sinkronizim.
- `BulkGenerationItem::REVIEW_REJECTED` dhe rruga `ai.reviews.reject`, që kthen 404 për produktet jo-demo.

Mbrojtja nga mbishkrimi mbetet e njëjtë: përmbajtja e importuar zëvendësohet vetëm me konfirmim të qartë.

## Sinkronizimi dhe konfliktet

`WooCommerceSyncPreviewService` dhe `WooCommerceSyncService` nuk kanë asnjë degë për demon. Një sinkronizim demo kalon nëpër të njëjtin rrjedhë:

1. Preview lexon vlerat aktuale dhe ruan hash-in e secilës fushë.
2. Përdoruesi zgjedh fushat dhe konfirmon.
3. Para shkrimit produkti lexohet përsëri.
4. Nëse hash-i ka ndryshuar, artikulli shënohet si konflikt dhe asgjë nuk mbishkruhet.
5. Përndryshe `updateProduct` shkruan në `woocommerce_demo_products`.

I vetmi dallim është te `WooCommerceSyncRunner`: dyqanet demo ekzekutohen gjithmonë gjatë kërkesës.

**Simulate Remote Change** thërret `simulateRemoteChange()`. Ajo zgjedh fushën e parë të ndryshuar, përveç `slug`, dhe e redakton përmes shërbimit normal. Për detektorin kjo duket njësoj si një ndryshim nga dikush tjetër në dyqan. Veprimi lejohet vetëm për dyqane demo dhe vetëm kur preview pret konfirmim.

## Fushat e mbrojtura

`WooCommerceDemoService::protectedFields()` krahason produktin aktual me regjistrimin origjinal te `DemoCatalog`, për çmimin, stokun, SKU-në, imazhet, statusin, variacionet, transportin dhe taksat.

Krahasimi bëhet me `==` dhe jo me `===`, sepse MySQL mund t'i rirendisë çelësat në kolonat JSON.

## Izolimi

Mekanizmi kryesor është një rresht te `User`:

```php
public function products(): HasMany
{
    return $this->hasMany(Product::class)->whereNull('demo_store_id');
}
```

Kështu produktet demo dalin automatikisht jashtë listës së produkteve, dashboard-it, eksporteve, importeve me skedar dhe përputhjes me SKU, pa ndryshuar secilin nga këto vende. Produktet demo arrihen përmes dyqanit të tyre, dhe faqja normale e produktit i shfaq me etiketën Demo.

## Reset

`WooCommerceDemoService::reset()` punon në një transaksion dhe nis gjithmonë nga një dyqan demo. Një dyqan real shkakton `LogicException`.

Radha e veprimeve:

1. Gjen produktet me `demo_store_id` të këtij dyqani dhe batch-et e tyre.
2. Fshin gjenerimet me `provider = sample` për këto produkte.
3. Fshin sync run-et, transferimet e importit të këtij dyqani dhe lidhjet.
4. Fshin produktet demo, bashkë me përmbajtjen dhe draftet e tyre.
5. Fshin vetëm batch-et që mbetën pa artikuj.
6. Rimbush katalogun dhe rivendos gjendjen e dyqanit.

Gjenerimet reale të Gemini mbeten me `product_id = null`, prandaj historiku ruhet dhe kuota nuk rimbushet.

## Ndërfaqja

| Pjesa | Vendndodhja |
| --- | --- |
| Faqja kryesore e demos | `resources/views/integrations/demo/show.blade.php` |
| Faqja e produktit të simuluar | `resources/views/integrations/demo/product.blade.php` |
| Banderola "Demo Mode — No Real WooCommerce Connection" | `resources/views/components/demo-banner.blade.php` |
| Rrugët | `routes/web.php`, emrat `integrations.demo.*` |

Faqet ekzistuese të importit, sinkronizimit, historikut, rishikimit dhe produktit shfaqin banderolën vetëm kur dyqani ose produkti është demo.

## Testet

Testet janë te `tests/Feature/WooCommerce/DemoModeTest.php` dhe ekzekutohen në SQLite në memorie. `tearDown` thërret `Http::assertNothingSent()`, kështu që çdo kërkesë e jashtme e rrëzon testin.

```bash
php artisan test --filter=DemoModeTest
```

## Kufizimet

- Demo nuk tregon sjelljen e një dyqani real: autentikimin, plugin-et e sigurisë, kufijtë e hostit ose filtrimin e HTML nga WordPress.
- Demo funksionon vetëm në anglisht dhe nuk teston WPML.
- Dyqani demo mund të rivendoset, por jo të hiqet nga faqja Integrations.
