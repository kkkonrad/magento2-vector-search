# Troubleshooting

## Awaria Embedding-Service Lub OpenSearch

Frontend pozostawia wtedy wynik natywnego wyszukiwania Magento. Pelny reindex konczy sie bledem
przed przelaczeniem aliasu, wiec dotychczasowy indeks pozostaje aktywny.

Sprawdz:

```bash
curl http://127.0.0.1:3000/health
php bin/magento indexer:status vector_search_products
curl http://127.0.0.1:9200/_cat/aliases/*vector*_current?v
```

Po przywroceniu uslugi uruchom:

```bash
php bin/magento indexer:reindex vector_search_products
php bin/magento vectorsearch:config:validate
php bin/magento vectorsearch:regression:run
```

## Produkt Niepasujacy Jest Wysoko

1. Uruchom:

```bash
php bin/magento vectorsearch:explain "fraza" --store=1 --limit=72
```

2. Sprawdz:

- `Intent`,
- `Attributes`,
- decyzje w `Reranking`,
- finalne top 10.

Jesli produkt ma `product=yes`, ale nie powinien, popraw `product_intent/rules`.

Jesli produkt ma `attr=...=yes`, ale nie powinien, sprawdz wartosc w `attr_*` i reguly atrybutow.

Jesli produkt ma `attr=...=no`, ale nadal jest wysoko, sprawdz czy atrybut nie ma trybu `soft`.

## Regula Atrybutu Nie Dziala

Uruchom:

```bash
php bin/magento vectorsearch:config:validate
```

Sprawdz:

- czy pole `attr_*` istnieje,
- czy `term_matches` jest wieksze niz 0,
- czy alias wskazuje prawidlowy kod atrybutu,
- czy tryb nie jest ustawiony na `off`.

## Inny Sklep, Inne Kody Atrybutow

Najpierw wygeneruj sugestie:

```bash
php bin/magento vectorsearch:config:suggest --sample-size=30
```

Potem dopasuj aliasy:

```text
material=material,fabric,composition
```

Na koncu uruchom:

```bash
php bin/magento vectorsearch:config:validate
```

## Zmiany Konfiguracji Nie Sa Widoczne

Uruchom:

```bash
php bin/magento cache:clean config vectorsearch full_page block_html
```

Jesli zmienily sie dane produktow albo indeksowane pola:

```bash
php bin/magento indexer:reindex vector_search_products
```

## Reranker Jest Wolny Albo Pada

Sprawdz:

```text
vectorsearch/reranking/timeout_ms
vectorsearch/reranking/circuit_failure_threshold
vectorsearch/reranking/circuit_cooldown_seconds
vectorsearch/reranking/limit
```

Wlacz metryki i obserwuj:

```text
timings_ms.reranker
reranking_failed
reranking_circuit_open
```

## Regresje Po Zmianach

Uruchom:

```bash
php bin/magento vectorsearch:regression:run
```

Jesli przypadek nie jest pokryty, dodaj go do:

```text
vectorsearch/regression/rules
```

Przyklad:

```text
niebieskie szorty | store=1 | limit=72 | min_results=12 | must_top=1002:3 | must_not_top=1919:3
```
