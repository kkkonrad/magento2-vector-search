# Metryki Skutecznosci

## Wlaczenie

Konfiguracja:

```text
vectorsearch/metrics/enabled = 1
```

Metryki sa domyslnie wylaczone.

## Format Logu

Po wlaczeniu modul zapisuje krotki log JSON dla kazdego obsluzonego searcha:

```text
[VectorSearch][metrics] {"query":"niebieskie szorty","store_id":1,...}
```

Metryki nie wymagaja `vector_debug_token` i nie zwracaja publicznego naglowka debugowego. Pelny debug przez `vectorsearch/diagnostics/*` nadal jest osobnym trybem.

## Payload

Payload zawiera m.in.:

- `query`,
- `store_id`,
- liczbe filtrow,
- paginacje,
- `total_count`,
- finalne `top_ids`,
- `page_ids`,
- `timings_ms`,
- informacje o cache,
- intencje produktowa,
- intencje atrybutow,
- wynik rerankingu,
- `reranking_failed`,
- `reranking_circuit_open`.

Przykladowe pola:

```json
{
  "query": "niebieskie szorty",
  "store_id": 1,
  "total_count": 72,
  "timings_ms": {
    "query_embedding": 20.5,
    "opensearch_search": 300.0,
    "reranker": 120.2
  },
  "product_intent": {
    "group": "shorts",
    "terms_count": 2
  },
  "attribute_intents": [
    {
      "attribute": "color",
      "group": "blue",
      "groups": ["blue"],
      "mode": "strict",
      "fields": ["color"]
    }
  ],
  "reranking": {
    "used": true,
    "attribute_mismatched_demoted_count": 11
  }
}
```

## Jak Czytac

- Wysokie `query_embedding` wskazuje problem z embedding service.
- Wysokie `opensearch_search` wskazuje problem z OpenSearch albo zbyt duzym limitem.
- Wysokie `reranker` wskazuje problem z rerankerem lub zbyt duzym `reranking/limit`.
- `attribute_mismatched_demoted_count` pokazuje, ile kandydatow spadlo przez guard atrybutowy.
- `reranking_circuit_open=true` oznacza, ze reranker zostal czasowo pominiety przez circuit breaker.
