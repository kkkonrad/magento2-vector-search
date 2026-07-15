# Testy

## Unit Testy

Caly zestaw unit testow modulu:

```bash
vendor/bin/phpunit app/code/Kkkonrad/VectorSearch/Test/Unit
```

Tylko logika search:

```bash
vendor/bin/phpunit app/code/Kkkonrad/VectorSearch/Test/Unit/Model/Search
```

Tylko komendy CLI:

```bash
vendor/bin/phpunit app/code/Kkkonrad/VectorSearch/Test/Unit/Console/Command
```

## Regression Suite

```bash
php bin/magento vectorsearch:regression:run
```

Regression suite sprawdza realna sciezke wyszukiwania i kolejnosc wynikow.

## Walidacja Konfiguracji

```bash
php bin/magento vectorsearch:config:validate
```

Ta komenda nie zastępuje regression suite, ale szybko wykrywa bledne reguly, aliasy i tryby.

## Przed Zmiana Produkcyjna

Minimalny zestaw:

```bash
vendor/bin/phpunit app/code/Kkkonrad/VectorSearch/Test/Unit
php bin/magento vectorsearch:config:validate
php bin/magento vectorsearch:regression:run
```

Test odpornosci reindexu:

1. zapisz indeks wskazywany przez alias `*_current`,
2. zatrzymaj embedding-service,
3. uruchom `indexer:reindex vector_search_products` i oczekuj bledu,
4. potwierdz, ze alias nadal wskazuje poprzedni indeks,
5. przywroc serwis i wykonaj poprawny reindex.
