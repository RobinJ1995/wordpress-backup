<?php
(require_once('./wp-config.php')) or die('wp-config.php not found. Run this script from the Wordpress installation directory (e.g. public_html).');

if (empty(DB_HOST)) {
    die('DB_HOST not set in wp-config.php');
} else if (empty(DB_NAME)) {
    die('DB_NAME not set in wp-config.php');
} else if (empty(DB_USER)) {
    die('DB_USER not set in wp-config.php');
} else if (empty(DB_PASSWORD)) {
    die('DB_PASSWORD not set in wp-config.php');
}

function exec_with_output($cmd) {
    $output = null;
    $exit_status = null;
    exec('bash -c "' . $cmd . '"', $output, $exit_status);

    return [$exit_status, implode(PHP_EOL, $output)];
}

$wp_install_dir = basename(getcwd());

$tar_present = exec_with_output('which tar')[0] === 0;
$gzip_present = exec_with_output('which gzip')[0] === 0;
$bzip2_present = exec_with_output('which bzip2')[0] === 0;
$zstd_present = exec_with_output('which zstd')[0] === 0;
$mysqldump_present = exec_with_output('which mysqldump')[0] === 0;

if (!$mysqldump_present) {
    die('mysqldump not installed. Can\'t proceed.');
}

$compression = null;
$fs_compression_command = null;
$db_compression_command = null;
$fs_output_filename = 'fs_backup.tar';
$db_output_filename = 'db_backup.sql';
if (!$tar_present) {
    die('tar command not found. Can\'t proceed.');
}
if ($zstd_present) {
    $compression = 'zstd';
    $fs_compression_command = 'zstd -1 --rm "fs_backup.tar"';
    $db_compression_command = 'zstd -1 --rm "db_backup.sql"';
    $fs_output_filename .= '.zst';
    $db_output_filename .= '.zst';
} else if ($gzip_present) { // Prefer gzip because bzip2 is significantly slower.
    $compression = 'gzip';
    $fs_compression_command = 'gzip -1 "fs_backup.tar"';
    $db_compression_command = 'gzip -1 "db_backup.sql"';
    $fs_output_filename .= '.gz';
    $db_output_filename .= '.gz';
} else if ($bzip2_present) {
    $compression = 'bzip2';
    $fs_compression_command = 'bzip2 -1 "fs_backup.tar"';
    $db_compression_command = 'bzip2 -1 "db_backup.sql"';
    $fs_output_filename .= '.bz2';
    $db_output_filename .= '.bz2';
}

$bash_vars = "
WP_DIR=\"$wp_install_dir\"
DB_HOST=\"" . DB_HOST . "\"
DB_NAME=\"" . DB_NAME . "\"
DB_USER=\"" . DB_USER . "\"
DB_PASSWORD=\"" . DB_PASSWORD . "\"
export MYSQL_PWD=\${DB_PASSWORD}
FS_BACKUP_OUTPUT_FILENAME=\"$fs_output_filename\"
";
$script = '#!/bin/bash

' . $bash_vars . '
set -xe

echo "Backing up database..."
mysqldump "${DB_NAME}" -h"${DB_HOST}" -u"${DB_USER}" > db_backup.sql
' . ($compression == null ? '' : $db_compression_command) . '

echo "Backing up filesystem..."
tar -cf "fs_backup.tar" "${WP_DIR}/"
' . ($compression == null ? '' : $fs_compression_command) . '

echo "Backup complete."
';

file_put_contents('../backup_wp.sh', $script) or die('Failed to write backup script to ../backup_wp.sh');
chmod('../backup_wp.sh', 750) or die('Failed to set permissions on ../backup_wp.sh');
chdir('..');
echo 'Starting backup...' . PHP_EOL;
$out = exec_with_output('./backup_wp.sh');

echo 'Backup script finished with exit status: ' . $out[0] . PHP_EOL;
echo $out[1] . PHP_EOL;

if ($out[0] === 0) {
    echo 'You can find your backups at ' . $fs_output_filename . ' and ' . $db_output_filename . ', one directory up from your Wordpress installation directory.';
}