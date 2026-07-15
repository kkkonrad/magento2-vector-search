# Konfiguracja

Ten dokument opisuje pola konfiguracji modulu `Kkkonrad_VectorSearch`.

## OpenSearch I Embedding

Podstawowe sciezki:

```text
vectorsearch/embedding/service_url
vectorsearch/embedding/api_key
vectorsearch/opensearch/index_name
vectorsearch/opensearch/search_type
vectorsearch/opensearch/search_limit
vectorsearch/opensearch/min_similarity
```

Typowy tryb pracy to `hybrid`, czyli polaczenie wyszukiwania leksykalnego i wektorowego.

Serwis domyslnie powinien nasluchiwac tylko na `127.0.0.1`. Jesli port jest dostepny poza hostem,
ustaw ten sam sekret w `vectorsearch/embedding/api_key` oraz `EMBEDDING_API_KEY` uslugi Node.js.
Plik unit systemd wczytuje zmienne z `/etc/default/magento-vector-search`, np.:

```text
EMBEDDING_API_KEY=dlugi-losowy-sekret
MAX_QUEUE_SIZE=256
MAX_REQUEST_TEXTS=512
MAX_TEXT_LENGTH=4000
```

Konfiguracja wag hybrydowych:

```text
vectorsearch/opensearch/hybrid_combination_technique
vectorsearch/opensearch/hybrid_normalization_technique
vectorsearch/opensearch/lexical_weight
vectorsearch/opensearch/knn_weight
```

## Reranking

Sciezki:

```text
vectorsearch/reranking/enabled
vectorsearch/reranking/limit
vectorsearch/reranking/min_score
vectorsearch/reranking/timeout_ms
vectorsearch/reranking/circuit_failure_threshold
vectorsearch/reranking/circuit_cooldown_seconds
```

Reranker porzadkuje kandydatow zwroconych przez OpenSearch. Circuit breaker chroni frontend przed powtarzajacymi sie awariami lub timeoutami rerankera.

## Intencje Produktowe

Sciezka:

```text
vectorsearch/product_intent/rules
```

Format:

```text
group=term1,term2
shorts=szort,spoden
pants=spodn,leggins,rybacz,capri
watches=zegar,zegarek,watch
```

Jesli query pasuje do grupy, wyniki bez termow tej grupy sa demotowane po rerankingu.

## Normalizacja Query

Sciezki:

```text
vectorsearch/query_normalization/synonym_rules
vectorsearch/query_normalization/stop_words
```

Przyklad:

```text
spodenki,szorty,shorts
zegarek,zegarki,watch
torba,torby,plecak,bag,backpack
```

Normalizer dopisuje warianty do query przed embeddingiem i OpenSearch.

## Intencje Atrybutow

Sciezki:

```text
vectorsearch/attribute_intent/rules
vectorsearch/attribute_intent/aliases
vectorsearch/attribute_intent/modes
```

### Reguly

Format:

```text
attribute:group=term1,term2
color:blue=niebiesk,niebieski,niebieskie,blue
material:cotton=bawełna,bawełniany,cotton
```

Jesli query zawiera termin z reguly, resolver sprawdza odpowiadajace pola indeksu `attr_*`.

### OR I AND

Kilka grup tego samego atrybutu dziala jako OR:

```text
czarne lub niebieskie szorty
```

wykrywa jedna intencje:

```text
color:black|blue
```

Produkt moze miec czarny albo niebieski kolor.

Rozne atrybuty dzialaja jak AND:

```text
niebieska bawełniana koszulka
```

wymaga dopasowania koloru i materialu.

### Aliasy Pol

Format:

```text
attribute=field1,field2
color=color
material=material,fabric,composition
```

Regula `material:cotton=...` sprawdzi:

```text
attr_material
attr_fabric
attr_composition
```

To jest kluczowe przy przenoszeniu modulu na sklep z innymi kodami atrybutow.

### Tryby

Format:

```text
attribute=strict|soft|off
color=strict
material=soft
brand=off
```

- `strict`: brak dopasowania mocno demotuje produkt.
- `soft`: brak dopasowania daje lzejsza kare w rerankingu.
- `off`: reguly danego atrybutu sa ignorowane.

Domyslnie brak wpisu oznacza `strict`.

## Diagnostyka I Metryki

Diagnostyka publiczna:

```text
vectorsearch/diagnostics/enabled
vectorsearch/diagnostics/token
```

Metryki:

```text
vectorsearch/metrics/enabled
```

Metryki zapisują krotki log JSON `[VectorSearch][metrics]` dla kazdego obsluzonego searcha. Pelna diagnostyka wymaga parametrow `vector_debug=1` i `vector_debug_token`.

Zmiana ustawien rankingu automatycznie zmienia fingerprint cache; nie trzeba czekac na wygasniecie
starych wynikow. Zmiana modelu lub pol indeksowanych nadal wymaga pelnego reindexu.
