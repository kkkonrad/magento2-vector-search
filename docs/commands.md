# Komendy CLI

Komendy nalezy uruchamiac z katalogu glownego Magento.

## Reindex

```bash
php bin/magento indexer:reindex vector_search_products
```

Komenda wykonuje readiness check, buduje wersjonowany indeks i atomowo przelacza alias `_current`.
Nieudany build nie zmienia aktywnego indeksu.

## Explain

```bash
php bin/magento vectorsearch:explain "niebieskie szorty" --store=1 --limit=72
```

Pokazuje:

- znormalizowane query,
- surowy top OpenSearch,
- wykryta intencje produktowa,
- wykryte intencje atrybutow,
- decyzje rerankera,
- finalne top 10.

Przyklad:

```text
Attributes: color:blue fields=attr_color
1919 | ... | product=yes | attr=color:blue[strict]=no | demoted
```

Przy OR w tym samym atrybucie:

```text
Attributes: color:blue|black fields=attr_color
```

## Regression Suite

```bash
php bin/magento vectorsearch:regression:run
```

Reguly sa w:

```text
vectorsearch/regression/rules
```

Format:

```text
query | store=1 | limit=72 | min_results=12 | must_top=1002:3 | must_not_top=1919:3
```

Znaczenie:

- `must_top=ID:POSITION`: produkt musi byc najpozniej na wskazanej pozycji.
- `must_not_top=ID:POSITION`: produkt nie moze byc w top N.
- `min_results`: minimalna liczba wynikow.

## Walidacja Konfiguracji

```bash
php bin/magento vectorsearch:config:validate --sample-size=5
```

Sprawdza:

- format regul atrybutow,
- format aliasow,
- format trybow,
- czy pola `attr_*` istnieja w mappingu OpenSearch,
- ile dokumentow ma dane pole,
- ile dokumentow pasuje do termow z reguly,
- probki wartosci z indeksu.

Dobry wynik:

```text
VectorSearch config validation: ok=9 warn=0 error=0
```

## Sugestie Konfiguracji

```bash
php bin/magento vectorsearch:config:suggest --attribute=color --sample-size=20 --max-terms=10
```

Komenda czyta wartosci z indeksu i proponuje:

- aliasy,
- tryby,
- szkielety regul,
- kuratorowane grupy dla znanych atrybutow, np. `color` i `material`,
- liczbe trafien w probkach oraz tokeny, ktore wywolaly sugestie.

Przyklad:

```text
Inspected fields:
  attr_color docs=148 terms=red(9: czerwon), blue(8: niebiesk), brown(1: brąz)

Suggested aliases:
color=color

Suggested modes:
color=strict

Suggested rules:
color:red=czerwon,czerwone,czerwony,red
color:blue=niebiesk,niebieski,niebieskie,blue,granat,granatowy,navy
color:brown=brąz,braz,brązowy,brazowy,brown
```

Po wklejeniu sugestii do konfiguracji uruchom:

```bash
php bin/magento vectorsearch:config:validate
```
