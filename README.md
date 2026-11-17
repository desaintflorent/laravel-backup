# brindepaille/laravel-backup

Sauvegarde d'un site Laravel vers le bucket S3 du studio : dump de la base avec [spatie/laravel-backup](https://github.com/spatie/laravel-backup) et miroir du disque de fichiers. On installe le package, on renseigne les variables d'environnement en production, et c'est tout : rien à publier, aucun fichier du site à modifier.

Le bucket, ses règles de cycle de vie et l'utilisateur IAM du site doivent déjà exister.

## Ce que fait le package

- Il déclare le disque `backups` (S3 `eu-west-3`, classe Glacier Instant Retrieval), sans jamais lire les variables `AWS_*` de l'hébergeur.
- Il règle Spatie : dumps dans `db/<nom>/` sur le disque `backups`, notifications coupées, zips chiffrés par `BACKUP_ARCHIVE_PASSWORD`.
- Il ajoute aux connexions MySQL et MariaDB les options de dump `--single-transaction` et `--no-tablespaces`.
- Il fournit la commande `backup:files`, qui aligne `files/<nom>/` sur le disque live : elle dépose ce qui manque et masque ce qui a disparu, sans jamais lire ni réécrire un fichier déjà sauvegardé. Un fichier modifié sans changer de nom garde donc sa première version dans la sauvegarde.
- Il planifie `backup:run --only-db` à 02:00 et `backup:files` à 02:30, et envoie par mail la sortie d'une tâche en échec.

## Installation

PHP 8.5 et Laravel 13 au minimum.

```bash
composer config repositories.brindepaille-backup vcs https://github.com/desaintflorent/laravel-backup
composer require brindepaille/laravel-backup
```

`composer require` sans contrainte demande une version taguée (`v1.0.0`, …) ; sans tag, précise la branche : `brindepaille/laravel-backup:dev-main`.

Le dépôt est public : aucun jeton n'est nécessaire, ni en local ni chez l'hébergeur.

## Variables d'environnement

À définir en production uniquement.

| Variable | Rôle |
| --- | --- |
| `BACKUP_NAME` | Nom du site, exactement celui de son utilisateur IAM. Donne `db/<nom>/` et `files/<nom>/`. |
| `BACKUP_S3_BUCKET` | Bucket de sauvegarde. |
| `BACKUP_S3_KEY` | Access key ID de l'utilisateur IAM. |
| `BACKUP_S3_SECRET` | Secret access key de l'utilisateur IAM. |

Ces quatre-là sont obligatoires. Les suivantes sont facultatives :

| Variable | Défaut | Rôle |
| --- | --- | --- |
| `BACKUP_FREQUENCY` | `daily` | `weekly` : dump de la base le dimanche seulement. Le miroir des fichiers reste quotidien. |
| `BACKUP_ALERT_EMAIL` | aucun | Adresse qui reçoit la sortie d'une tâche en échec. Vide : aucun mail. |
| `BACKUP_ARCHIVE_PASSWORD` | aucun | Mot de passe des zips (AES-256). Vide : zips non chiffrés. |
| `BACKUP_FILES_DISK` | disque par défaut | Disque à recopier, par exemple `r2`. |
| `BACKUP_ON_ONE_SERVER` | `false` | `true` quand l'environnement tourne sur plusieurs replicas. |
| `BACKUP_S3_REGION` | `eu-west-3` | Région du bucket ; l'endpoint en découle. |
| `BACKUP_S3_STORAGE_CLASS` | `GLACIER_IR` | `STANDARD` pour rester en stockage standard. |

## Fonctionnement

Sans aucune des quatre variables obligatoires, le package est inactif : pas de disque `backups`, aucune tâche planifiée. C'est l'état normal en local et en test.

Dès qu'une des quatre est définie, les deux tâches sont planifiées. S'il en manque encore, elles échouent chaque nuit et `backup:files` nomme les variables manquantes : une configuration incomplète se voit, à condition que `BACKUP_ALERT_EMAIL` soit renseignée et que le site sache envoyer des mails.

Le scheduler doit tourner : sur Laravel Cloud, active *Scheduler* sur le cluster App puis redéploie ; ailleurs, le cron habituel `php artisan schedule:run`. Toute modification des variables demande un redéploiement.

Si le disque live répond vide alors que la sauvegarde contient des fichiers, `backup:files` s'arrête en échec sans rien masquer.

Les commandes se lancent aussi à la main :

```bash
php artisan backup:run --only-db
php artisan backup:files -v
```

`-v` affiche chaque fichier déposé ou masqué. Lance la première copie d'un gros disque à la main avec cette option : elle peut durer, et relancer la commande reprend là où elle s'est arrêtée.

Pas de `backup:clean` ni de `backup:monitor` sur les sites : le cycle de vie du bucket fait le ménage, et la clé du site n'a pas le droit de lire les sauvegardes.

## Migrer un site déjà configuré à la main

Les variables d'environnement ne changent pas. Dans le même commit que l'installation du package, retire du site :

- `app/Console/Commands/BackupFiles.php` ;
- `config/backup.php` et `lang/vendor/backup/` ;
- le disque `backups` de `config/filesystems.php` ;
- la clé `dump` de la connexion `mysql` dans `config/database.php` ;
- l'entrée `backup` de `config/services.php` ;
- le bloc de planification des sauvegardes de `routes/console.php` ;
- `spatie/laravel-backup` du `composer.json` (le package l'apporte).

Tant que ces éléments restent en place, les tâches sont planifiées en double.

Trois réglages faits à la main sur un site deviennent des variables :

- `backup:files --disk=…` dans la planification : `BACKUP_FILES_DISK` ;
- la ligne `options` retirée du disque pour rester en stockage standard : `BACKUP_S3_STORAGE_CLASS=STANDARD` ;
- `->onOneServer()` sur les tâches : `BACKUP_ON_ONE_SERVER=true`.

## Réglages avancés

Les heures de lancement et le détail du disque se changent en publiant la configuration :

```bash
php artisan vendor:publish --tag=brindepaille-backup-config
```

Une application qui définit elle-même un disque `backups` ou qui publie `config/backup.php` garde ses réglages : le package n'y remplace que le nom, le disque de destination, les notifications et, si `BACKUP_ARCHIVE_PASSWORD` est renseignée, le mot de passe des zips.

## Développement

```bash
composer install
composer test
```

`composer test` enchaîne Pint, PHPStan et PHPUnit.
