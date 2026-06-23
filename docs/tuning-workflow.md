# Workflow Strojenia Wynikow

## Standardowa Petla

1. Sprawdz problematyczna fraze:

```bash
php bin/magento vectorsearch:explain "fraza" --store=1 --limit=72
```

2. Ustal typ problemu:

- produktowy: zla rodzina produktu,
- atrybutowy: zly kolor/material/inny atrybut,
- rankingowy: kandydaci sa dobrzy, ale kolejnosc jest slaba,
- infrastrukturalny: timeout, circuit breaker, brak embeddingu.

3. Dla problemu produktowego zmien:

```text
vectorsearch/product_intent/rules
```

4. Dla problemu atrybutowego sprawdz:

```text
vectorsearch/attribute_intent/rules
vectorsearch/attribute_intent/aliases
vectorsearch/attribute_intent/modes
```

5. Po zmianach uruchom:

```bash
php bin/magento cache:clean config vectorsearch full_page block_html
php bin/magento vectorsearch:config:validate
php bin/magento vectorsearch:regression:run
```

6. Jesli zmienily sie dane produktow, pola indeksu albo embedding text:

```bash
php bin/magento indexer:reindex vector_search_products
```

## Przyklad: Niebieskie Szorty

Problem: produkt Fiona byl zbyt wysoko dla frazy `niebieskie szorty`, mimo ze nie mial niebieskiego koloru.

Konfiguracja:

```text
attribute_intent/aliases:
color=color

attribute_intent/modes:
color=strict

attribute_intent/rules:
color:blue=niebiesk,niebieski,niebieskie,blue
```

Oczekiwany explain:

```text
attr=color:blue[strict]=no | demoted
```

## Przyklad: OR W Kolorze

Fraza:

```text
czarne lub niebieskie szorty
```

Oczekiwane wykrycie:

```text
Attributes: color:blue|black fields=attr_color
```

Produkt z kolorem czarnym albo niebieskim moze przejsc guard.

## Checklist Przed Wdrozeniem Na Inny Sklep

1. Uruchom reindex.
2. Uruchom `vectorsearch:config:suggest`.
3. Dopasuj `attribute_intent/aliases` do kodow atrybutow sklepu.
4. Ustaw `attribute_intent/modes`.
5. Uruchom `vectorsearch:config:validate`.
6. Dodaj najwazniejsze frazy do `regression/rules`.
7. Uruchom `vectorsearch:regression:run`.
8. Sprawdz kilka fraz przez `vectorsearch:explain`.
