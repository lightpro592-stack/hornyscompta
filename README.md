# Horny's Comptabilite

Petite application PHP/MySQL pour XAMPP avec :

- connexion admin : `admin` / `hornys2611`
- comptes employes avec PIN a 4 chiffres
- ajout d'ecritures comptables
- tableau des entrees, sorties et solde
- sauvegarde en base MySQL `hornys_compta`

## Installation XAMPP

1. Copie le dossier du projet dans `C:\xampp\htdocs\hornys-compta`.
2. Lance Apache et MySQL depuis le panneau XAMPP.
3. Ouvre phpMyAdmin et importe `sql/schema.sql`, ou cree simplement une base nommee `hornys_compta`.
4. Va sur `http://localhost/hornys-compta/`.
5. Connecte-toi avec `admin` / `hornys2611`.

Le compte admin est ajoute automatiquement au premier chargement du site.

## Configuration

Les identifiants MySQL sont dans `config.php` :

```php
const DB_HOST = '127.0.0.1';
const DB_NAME = 'hornys_compta';
const DB_USER = 'root';
const DB_PASS = '';
```

Ce sont les valeurs par defaut de XAMPP. Si ton MySQL a un mot de passe, modifie `DB_PASS`.
