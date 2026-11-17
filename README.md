# brindepaille/laravel-backup

Sauvegarde d'un site Laravel vers le bucket S3 du studio : dump de la base avec [spatie/laravel-backup](https://github.com/spatie/laravel-backup) et miroir des disques de fichiers. On installe le package, on renseigne les variables d'environnement en production, et c'est tout : rien à publier, aucun fichier du site à modifier.

Le bucket, ses règles de cycle de vie et l'utilisateur IAM du site doivent déjà exister.

## Ce que fait le package

- Il déclare le disque `backups` (S3 `eu-west-3`, classe Glacier Instant Retrieval), sans jamais lire les variables `AWS_*` de l'hébergeur.
- Il règle Spatie : dumps dans `db/<nom>/` sur le disque `backups`, notifications coupées, zips chiffrés par `BACKUP_ARCHIVE_PASSWORD`.
- Il ajoute aux connexions MySQL et MariaDB les options de dump `--single-transaction` et `--no-tablespaces`.
- Il fournit la commande `backup:files`, qui aligne `files/<nom>/<disque>/` sur chaque disque live : elle dépose ce qui manque et masque ce qui a disparu, sans jamais lire ni réécrire un fichier déjà sauvegardé. Un fichier modifié sans changer de nom garde donc sa première version dans la sauvegarde.
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
| `BACKUP_NAME` | Nom du site, exactement celui de son utilisateur IAM. Donne `db/<nom>/` et `files/<nom>/<disque>/`. |
| `BACKUP_S3_BUCKET` | Bucket de sauvegarde. |
| `BACKUP_S3_KEY` | Access key ID de l'utilisateur IAM. |
| `BACKUP_S3_SECRET` | Secret access key de l'utilisateur IAM. |

Ces quatre-là sont obligatoires. Les suivantes sont facultatives :

| Variable | Défaut | Rôle |
| --- | --- | --- |
| `BACKUP_FREQUENCY` | `daily` | `weekly` : dump de la base le dimanche seulement. Le miroir des fichiers reste quotidien. |
| `BACKUP_ALERT_EMAIL` | aucun | Adresse qui reçoit la sortie d'une tâche en échec. Vide : aucun mail. |
| `BACKUP_ARCHIVE_PASSWORD` | aucun | Mot de passe des zips (AES-256). Vide : zips non chiffrés. |
| `BACKUP_DATABASES` | connexion de `DB_CONNECTION` | Connexions à dumper, séparées par des virgules : `mysql,reporting`. |
| `BACKUP_FILES_DISK` | disque par défaut | Disque à recopier, par exemple `r2`, ou plusieurs séparés par des virgules : `local,public`. |
| `BACKUP_ON_ONE_SERVER` | `false` | `true` quand l'environnement tourne sur plusieurs replicas. |
| `BACKUP_S3_REGION` | `eu-west-3` | Région du bucket ; l'endpoint en découle. |
| `BACKUP_S3_STORAGE_CLASS` | `GLACIER_IR` | `STANDARD` pour rester en stockage standard. |

## Fonctionnement

Sans aucune des quatre variables obligatoires, le package est inactif : pas de disque `backups`, aucune tâche planifiée. C'est l'état normal en local et en test.

Dès qu'une des quatre est définie, les deux tâches sont planifiées. S'il en manque encore, elles échouent chaque nuit et `backup:files` nomme les variables manquantes : une configuration incomplète se voit, à condition que `BACKUP_ALERT_EMAIL` soit renseignée et que le site sache envoyer des mails.

Le scheduler doit tourner : sur Laravel Cloud, active *Scheduler* sur le cluster App puis redéploie ; ailleurs, le cron habituel `php artisan schedule:run`. Toute modification des variables demande un redéploiement.

Si un disque live répond vide alors que sa sauvegarde contient des fichiers, `backup:files` le signale en échec sans rien y masquer, et traite quand même les autres disques.

### Plusieurs bases

`BACKUP_DATABASES` liste les connexions de `config/database.php` à dumper. Tous les dumps partent dans le même zip de `db/<nom>/`, un fichier par connexion, nommé d'après elle (`mysql-reporting.sql`). Une connexion inconnue ou injoignable met `backup:run` en échec : aucun zip n'est déposé cette nuit-là.

### Plusieurs disques

Chaque disque de `BACKUP_FILES_DISK` a son dossier, nommé comme lui : `files/<nom>/local/`, `files/<nom>/public/`. Un miroir ne masque que les fichiers de son propre dossier.

La sauvegarde suit la liste. Une fois tous les disques recopiés sans erreur, `backup:files` masque ce qui reste dans `files/<nom>/` hors de leurs dossiers : retirer un disque de la liste, ou changer le disque par défaut quand la variable est vide, masque donc sa sauvegarde au passage suivant. Les fichiers masqués restent récupérables jusqu'à l'échéance fixée par le bucket.

Un nom de disque inconnu arrête la commande avant qu'elle ne touche à la sauvegarde.

Les commandes se lancent aussi à la main :

```bash
php artisan backup:run --only-db
php artisan backup:files -v
```

`-v` affiche chaque fichier déposé ou masqué. Lance la première copie d'un gros disque à la main avec cette option : elle peut durer, et relancer la commande reprend là où elle s'est arrêtée.

`--disk=public` limite le passage à un disque de la liste, sans toucher au reste de la sauvegarde.

Pas de `backup:clean` ni de `backup:monitor` sur les sites : le cycle de vie du bucket fait le ménage, et la clé du site n'a pas le droit de lire les sauvegardes.

## Mettre à jour depuis la version 1

La version 1 recopiait le disque directement dans `files/<nom>/`. Il n'y a rien à changer sur le site : ni variable, ni planification.

Au premier passage après la mise à jour, `backup:files` recopie tout le disque dans `files/<nom>/<disque>/`, puis masque l'ancienne copie. Elle ne la masque qu'une fois la nouvelle complète : un passage interrompu ou en échec laisse l'ancienne en place, et le suivant reprend.

À prévoir :

- la nouvelle copie repart de zéro : pour un gros disque, lance-la à la main juste après le déploiement (`php artisan backup:files -v`) ;
- le bucket garde les deux copies jusqu'à l'échéance des versions masquées ;
- pour restaurer, les fichiers sont désormais sous `files/<nom>/<disque>/`.

Seul cas à vérifier avant la mise à jour : un dossier à la racine du disque qui porte le nom du disque lui-même (`local/` sur le disque `local`). Ses fichiers déjà sauvegardés seraient pris pour ceux de la nouvelle copie.

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

La sauvegarde des fichiers faite à la main est dans `files/<nom>/` : elle est remplacée comme décrit dans « Mettre à jour depuis la version 1 ».

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
