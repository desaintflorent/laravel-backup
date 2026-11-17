<?php

return [

    /*
     * Nom du site : exactement le nom de son utilisateur IAM. Il donne les
     * dossiers db/<nom>/ (dumps) et files/<nom>/<disque>/ (fichiers) dans le bucket.
     */
    'name' => env('BACKUP_NAME'),

    /*
     * Rythme du dump de la base : « daily » ou « weekly » (le dimanche).
     * Le miroir des fichiers reste quotidien dans les deux cas.
     */
    'frequency' => env('BACKUP_FREQUENCY', 'daily'),

    /*
     * Connexions dont la base est dumpée, séparées par des virgules :
     * « mysql,reporting ». Vide : la connexion de DB_CONNECTION.
     */
    'databases' => env('BACKUP_DATABASES'),

    /*
     * Adresse qui reçoit la sortie d'une tâche en échec. Vide : aucun mail.
     */
    'alert' => env('BACKUP_ALERT_EMAIL'),

    /*
     * Mot de passe des zips (AES-256). Vide : les zips ne sont pas chiffrés.
     */
    'archive_password' => env('BACKUP_ARCHIVE_PASSWORD'),

    /*
     * Disques dont les fichiers sont recopiés dans la sauvegarde, séparés par
     * des virgules : « local,public ». Vide : le disque par défaut de l'application.
     */
    'files_disk' => env('BACKUP_FILES_DISK'),

    /*
     * À activer quand l'environnement tourne sur plusieurs replicas : sans
     * cela, chaque tâche s'exécute sur chacun d'eux.
     */
    'on_one_server' => (bool) env('BACKUP_ON_ONE_SERVER', false),

    /*
     * Heures de lancement, dans le fuseau du scheduler de l'application.
     */
    'schedule' => [
        'database' => '02:00',
        'files' => '02:30',
    ],

    /*
     * Le disque « backups ». Rien n'est lu dans les variables AWS_* : elles
     * sont injectées par l'hébergeur pour le bucket live, et la sauvegarde
     * partirait dedans. La classe de stockage s'applique à chaque dépôt.
     */
    'disk' => [
        'driver' => 's3',
        'key' => env('BACKUP_S3_KEY'),
        'secret' => env('BACKUP_S3_SECRET'),
        'region' => env('BACKUP_S3_REGION', 'eu-west-3'),
        'bucket' => env('BACKUP_S3_BUCKET'),
        'endpoint' => 'https://s3.'.env('BACKUP_S3_REGION', 'eu-west-3').'.amazonaws.com',
        'use_path_style_endpoint' => false,
        'throw' => true,
        'options' => [
            'StorageClass' => env('BACKUP_S3_STORAGE_CLASS', 'GLACIER_IR'),
        ],
    ],

];
