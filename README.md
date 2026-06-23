# Kkkonrad_VectorSearch

Modul dodaje hybrydowe wyszukiwanie produktow dla Magento 2. OpenSearch laczy wynik leksykalny z wektorowym, a opcjonalny reranker porzadkuje kandydatow przed zwroceniem listy produktow.

## Szybki Start

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

## Minimalny Zestaw Kontrolny

```bash
vendor/bin/phpunit app/code/Kkkonrad/VectorSearch/Test/Unit
php bin/magento vectorsearch:config:validate
php bin/magento vectorsearch:regression:run
```
