# Kkkonrad_VectorSearch

Modul dodaje hybrydowe wyszukiwanie produktow dla Magento 2. OpenSearch laczy wynik leksykalny z wektorowym, a opcjonalny reranker porzadkuje kandydatow przed zwroceniem listy produktow.

## Wymagania

- Magento 2.4 z wlaczonym `Magento_OpenSearch` i dzialajacym indeksem katalogowym.
- OpenSearch 2.x z pluginem k-NN.
- PHP 8.1 lub nowszy z rozszerzeniem cURL.
- Node.js 18 lub nowszy oraz npm.
- Minimum ok. 1 GB wolnej pamieci RAM dla embedding-service.
- Dostep do uruchamiania procesow systemd, Supervisor albo innego process managera.

Modul korzysta z hosta, portu, uwierzytelnienia i prefiksu indeksu skonfigurowanego dla
natywnego wyszukiwania Magento. Przed instalacja sprawdz polaczenie z OpenSearch:

```bash
curl http://127.0.0.1:9200/
php bin/magento config:show catalog/search/engine
```

## Instalacja

Wykonaj polecenia z glownego katalogu Magento.

### 1. Pobranie modulu

```bash
mkdir -p app/code/Kkkonrad
git clone https://github.com/kkkonrad/magento2-vector-search.git app/code/Kkkonrad/VectorSearch
```

Jesli kod zostal dostarczony jako archiwum, jego katalogiem docelowym musi byc:

```text
app/code/Kkkonrad/VectorSearch
```

### 2. Instalacja embedding-service

```bash
cd app/code/Kkkonrad/VectorSearch/embedding-service
npm ci --omit=dev
sudo install -d -o www-data -g www-data models
cd ../../../../..
```

Pierwsze uruchomienie pobiera modele do katalogu `embedding-service/models` i moze potrwac
kilka minut. Katalog ten nie jest przechowywany w Git.

### 3. Uruchomienie embedding-service przez systemd

Plik unit zaklada, ze Magento znajduje sie w `/var/www/html`, a PHP/serwer WWW dziala jako
`www-data`. Jesli instalacja uzywa innej sciezki lub uzytkownika, popraw przed instalacja pola
`User`, `WorkingDirectory` i `ReadWritePaths` w pliku
`embedding-service/embedding-service.service`.

```bash
sudo cp app/code/Kkkonrad/VectorSearch/embedding-service/embedding-service.service \
    /etc/systemd/system/magento-vector-search.service
sudo systemctl daemon-reload
sudo systemctl enable --now magento-vector-search.service
sudo systemctl status magento-vector-search.service
```

Sprawdz gotowosc modelu:

```bash
curl http://127.0.0.1:3000/health
```

Oczekiwana odpowiedz zawiera `"status":"ok"`, nazwe modelu i dodatni wymiar embeddingu.
Logi uslugi:

```bash
sudo journalctl -u magento-vector-search.service -f
```

Na srodowisku developerskim bez systemd mozna tymczasowo uzyc:

```bash
cd app/code/Kkkonrad/VectorSearch/embedding-service
screen -dmS vectorsearch-embedding node server.js
cd ../../../../..
```

`screen` nie jest zalecanym process managerem na produkcji.

### 4. Wlaczenie modulu Magento

Embedding-service musi zwracac status `ok` przed wykonaniem pierwszego `setup:upgrade` lub
reindexu.

```bash
php bin/magento module:enable Kkkonrad_VectorSearch
php bin/magento setup:upgrade
php bin/magento cache:clean config vectorsearch full_page block_html
```

W trybie produkcyjnym wykonaj dodatkowo:

```bash
php bin/magento setup:di:compile
php bin/magento setup:static-content:deploy -f
```

### 5. Konfiguracja

Konfiguracja znajduje sie w panelu administracyjnym:

```text
Stores -> Configuration -> Kkkonrad -> Vector Search
```

Minimalnie sprawdz:

- `Embedding Service / Service URL`: domyslnie `http://localhost:3000`;
- `OpenSearch / Nazwa indeksu`: domyslnie `magento_products_vector`;
- `OpenSearch / Typ wyszukiwania`: zalecane `hybrid`;
- limit wyszukiwania i wagi lexical/kNN;
- reguly intencji i synonimow odpowiednie dla katalogu sklepu.

Serwis domyslnie nasluchuje tylko na `127.0.0.1`. Przy dostepie sieciowym ustaw wspolny sekret:

1. w `/etc/default/magento-vector-search` dodaj `EMBEDDING_API_KEY=dlugi-losowy-sekret`;
2. ten sam sekret zapisz jako `Embedding Service / Service API key` w panelu Magento;
3. wykonaj `sudo systemctl restart magento-vector-search.service`.

Nie zapisuj sekretu bezposrednio przez `config:set`: pole w panelu korzysta z szyfrowanego backendu
Magento. Szczegoly pozostalych ustawien: [docs/configuration.md](docs/configuration.md).

### 6. Pierwszy reindex

```bash
php bin/magento indexer:reindex vector_search_products
php bin/magento indexer:status vector_search_products
php bin/magento vectorsearch:config:validate
```

Indekser powinien miec status `Ready`, a walidator nie powinien zwracac bledow. Modul buduje
wersjonowany indeks i dopiero po poprawnej walidacji liczby dokumentow atomowo przelacza alias
`*_current`.

Sprawdz indeks i alias:

```bash
curl 'http://127.0.0.1:9200/_cat/aliases/*vector*_current?v'
curl 'http://127.0.0.1:9200/_cat/indices/*vector*?v'
```

### 7. Test wyszukiwania i podpowiedzi

```bash
php bin/magento vectorsearch:explain "testowa fraza" --store=1 --limit=20
curl 'https://twoj-sklep.example/vectorsearch/ajax/suggest?q=test'
```

Opcjonalnie uruchom regression suite:

```bash
php bin/magento vectorsearch:regression:run
```

Domyslne reguly regresji odnosza sie do produktow z katalogu przykladowego Magento Luma.
Przed uzyciem na innym katalogu zastap je w `vectorsearch/regression/rules` wlasnymi przypadkami.

## Aktualizacja Modulu

```bash
git -C app/code/Kkkonrad/VectorSearch pull --ff-only
cd app/code/Kkkonrad/VectorSearch/embedding-service
npm ci --omit=dev
cd ../../../../..
php bin/magento setup:upgrade
php bin/magento setup:di:compile
php bin/magento cache:clean config vectorsearch full_page block_html
sudo cp app/code/Kkkonrad/VectorSearch/embedding-service/embedding-service.service \
    /etc/systemd/system/magento-vector-search.service
sudo systemctl daemon-reload
sudo systemctl restart magento-vector-search.service
curl http://127.0.0.1:3000/health
php bin/magento indexer:reindex vector_search_products
```

## Brak Podpowiedzi

Najpierw sprawdz serwis i endpoint bezposrednio:

```bash
curl http://127.0.0.1:3000/health
curl 'https://twoj-sklep.example/vectorsearch/ajax/suggest?q=plecak'
```

Jesli health check nie zwraca `status=ok`, sprawdz log uslugi. Po jej przywroceniu wyczysc cache:

```bash
php bin/magento cache:clean vectorsearch full_page block_html
```

Przegladarka przechowuje odpowiedz podpowiedzi do 5 minut; po naprawie wykonaj twarde odswiezenie
strony (`Ctrl+F5`). Samo wyszukiwanie wynikow ma fallback do natywnego Magento, ale produkty w
panelu podpowiedzi wymagaja dzialajacego embedding-service.

## Szybka Weryfikacja

Po zmianach konfiguracji:

```bash
php bin/magento cache:clean config vectorsearch full_page block_html
php bin/magento vectorsearch:config:validate
php bin/magento vectorsearch:regression:run
```

Po zmianach danych produktow albo indeksowanych pol:

```bash
php bin/magento indexer:reindex vector_search_products
```

Diagnostyka pojedynczej frazy:

```bash
php bin/magento vectorsearch:explain "niebieskie szorty" --store=1 --limit=72
```

## Dokumentacja

- [Konfiguracja](docs/configuration.md)
- [Komendy CLI](docs/commands.md)
- [Workflow strojenia wynikow](docs/tuning-workflow.md)
- [Metryki skutecznosci](docs/metrics.md)
- [Troubleshooting](docs/troubleshooting.md)
- [Testy](docs/testing.md)

## Najwazniejsze Funkcje

- Hybrid search: lexical + vector.
- Query normalization: synonimy i stop words.
- Product intent guard.
- Attribute intent guard z aliasami pol.
- OR w obrebie tego samego atrybutu, AND miedzy roznymi atrybutami.
- Tryby atrybutow: `strict`, `soft`, `off`.
- Reranking z timeoutem i circuit breakerem.
- Debug CLI: `vectorsearch:explain`.
- Regression suite: `vectorsearch:regression:run`.
- Walidator konfiguracji: `vectorsearch:config:validate`.
- Sugestie konfiguracji: `vectorsearch:config:suggest`.
- Metryki search: `[VectorSearch][metrics]`.
- Atomowy reindex przez wersjonowany indeks i alias `_current`.
- Automatyczny fallback do natywnego search Magento przy awarii backendu wektorowego.
- Filtrowanie ceny, website/store i dostępności przed paginacją wyników.

## Bezpieczny Reindex

Pełny reindex najpierw sprawdza embedding-service, buduje osobny indeks, a dopiero po poprawnym
zapisaniu wszystkich dokumentów atomowo przełącza alias. Nie usuwaj ręcznie indeksów `_v_*` ani
aliasu `_current`; moduł zachowuje dwie ostatnie wersje do szybkiego rollbacku.

## Wydajnosc Indeksowania

Pelny reindex ponownie wykorzystuje embeddingi z aktywnego indeksu, gdy nie zmienily sie tekst
produktu ani model embeddingowy. Pierwszy reindex oraz produkty ze zmieniona trescia nadal wymagaja
obliczenia nowych wektorow. W logu `var/log/system.log` kazda partia zawiera czas etapow:
przygotowania tekstu, sprawdzenia hashy, embeddingu i zapisu bulk do OpenSearch.

Embedding-service powinien dzialac stale. Jego zimny start obejmuje zaladowanie modelu i moze trwac
kilkadziesiat sekund, ale po zmianie glowny endpoint `/embed` zaczyna odpowiadac bez oczekiwania na
zaladowanie opcjonalnego rerankera.

Parametry wydajnosci mozna ustawic w `/etc/default/magento-vector-search`:

```bash
INTRA_OP_THREADS=4
INTER_OP_THREADS=4
MAX_BATCH_SIZE=64
```

Po zmianie uruchom ponownie usluge i porownaj czasy na rzeczywistych danych:

```bash
sudo systemctl restart magento-vector-search.service
curl http://127.0.0.1:3000/health
php bin/magento indexer:reindex vector_search_products
grep '\[VectorSearch\].*Full reindex complete' var/log/system.log | tail -1
```

Wieksza liczba watkow nie zawsze oznacza szybsze przetwarzanie z powodu narzutu i konkurencji o CPU.
Domyslne `4/4` jest bezpiecznym punktem startowym; zmieniaj jedna wartosc naraz i zachowuj wynik,
ktory poprawia czas bez pogorszenia opoznien wyszukiwania.

## Minimalny Zestaw Kontrolny

```bash
vendor/bin/phpunit app/code/Kkkonrad/VectorSearch/Test/Unit
php bin/magento vectorsearch:config:validate
php bin/magento vectorsearch:regression:run
```
