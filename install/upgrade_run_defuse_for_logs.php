<?php
/**
 * @package       upgrade_run_defuse_for_logs.php
 * @author        Nils Laumaillé <nils@teampass.net>
 * @version       2.1.27
 * @copyright     2009-2019 Nils Laumaillé
 * @license       GNU GPL-3.0
 * @link          https://www.teampass.net
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 */

/*
** Upgrade script for release 2.1.27
*/
require_once('../sources/SecureHandler.php');
session_start();
error_reporting(E_ERROR | E_PARSE);
$_SESSION['db_encoding'] = "utf8";
$_SESSION['CPM'] = 1;

require_once '../includes/language/english.php';
require_once '../includes/config/include.php';
require_once '../includes/config/settings.php';
require_once '../sources/main.functions.php';
require_once '../includes/config/tp.config.php';

// Prepare POST variables
$post_nb = filter_input(INPUT_POST, 'nb', FILTER_SANITIZE_NUMBER_INT);
$post_start = filter_input(INPUT_POST, 'start', FILTER_SANITIZE_NUMBER_INT);

// Some init
$_SESSION['settings']['loaded'] = "";
$finish = false;
$next = ($post_nb + $post_start);

// Test DB connexion
$pass = defuse_return_decrypted($pass);
if (mysqli_connect(
    $server,
    $user,
    $pass,
    $database,
    $port
)
) {
    $db_link = mysqli_connect(
        $server,
        $user,
        $pass,
        $database,
        $port
    );
} else {
    $res = "Impossible to get connected to server. Error is: ".addslashes(mysqli_connect_error());
    echo '[{"finish":"1", "error":"Impossible to get connected to server. Error is: '.addslashes(mysqli_connect_error()).'!"}]';
    mysqli_close($db_link);
    exit();
}

// Get old saltkey from saltkey_ante_2127
$db_sk = mysqli_fetch_array(
    mysqli_query(
        $db_link,
        "SELECT valeur FROM ".$pre."misc
        WHERE type='admin' AND intitule = 'saltkey_ante_2127'"
    )
);
if (isset($db_sk['valeur']) && empty($db_sk['valeur']) === false) {
    $old_saltkey = $db_sk['valeur'];
} else {
    echo '[{"finish":"1" , "error":"Previous Saltkey not in database."}]';
    exit();
}

// Read saltkey
$ascii_key = file_get_contents(SECUREPATH."/teampass-seckey.txt");


// Get total items
$rows = mysqli_query(
    $db_link,
    "SELECT * FROM ".$pre."log_items
    WHERE encryption_type = 'not_set'"
);
if (!$rows) {
    echo '[{"finish":"1" , "error":"'.mysqli_error($db_link).'"}]';
    exit();
}

$total = mysqli_num_rows($rows);

// loop on items
$rows = mysqli_query(
    $db_link,
    "SELECT increment_id, id_item, raison, raison_iv, encryption_type FROM ".$pre."log_items
    WHERE encryption_type = 'not_set' LIMIT ".$post_start.", ".$post_nb
);
if (!$rows) {
    echo '[{"finish":"1" , "error":"'.mysqli_error($db_link).'"}]';
    exit();
}

while ($data = mysqli_fetch_array($rows)) {
    // extract encrypted string
    $pwd_tmp = explode(":", $data['raison']);

    if ($data['encryption_type'] !== "defuse" && substr($pwd_tmp[1], 0, 3) !== "def" && trim($pwd_tmp[0]) === "at_pw") {
        // decrypt with phpCrypt
        $old_pw = cryption_phpCrypt(
            $pwd_tmp[1],
            $old_saltkey,
            $data['raison_iv'],
            "decrypt"
        );

        // encrypt with Defuse
        $new_pw = cryption(
            $old_pw['string'],
            $ascii_key,
            "encrypt"
        );

        // store Password
        mysqli_query(
            $db_link,
            "UPDATE ".$pre."log_items
            SET raison = 'at_pw :".$new_pw['string']."', raison_iv = '', encryption_type = 'defuse'
            WHERE increment_id = ".$data['increment_id']
        );
    } elseif (substr($pwd_tmp[1], 0, 3) === "def" && $data['encryption_type'] !== "defuse") {
        mysqli_query(
            $db_link,
            "UPDATE ".$pre."log_items
            SET encryption_type = 'defuse'
            WHERE increment_id = ".$data['increment_id']
        );
    }
}

if ($next >= $total) {
    $finish = 1;
}


echo '[{"finish":"'.$finish.'" , "next":"'.$next.'", "error":""}]';
