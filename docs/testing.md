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
