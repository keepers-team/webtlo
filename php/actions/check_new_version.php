<?php

try {
    include_once dirname(__FILE__) . '/../common.php';
    $infoFromGitHub = getInfoFromGitHub();
    if ($infoFromGitHub != false || !is_null($infoFromGitHub)) {
        if (version_compare($_POST['current_version'], $infoFromGitHub['name']) > 0) {
            $newVersionNumber = $infoFromGitHub['name'];
            $newVersionAvailable = true;
            $newVersionLink = $infoFromGitHub['zipball_url'];
            $whatsNew = $infoFromGitHub['body'];
        } else {
            $newVersionNumber = '';
            $newVersionAvailable = false;
            $newVersionLink = "";
            $whatsNew = "";
        }
        echo json_encode(
            array(
                'log' => '',
                'newVersionNumber' => $newVersionNumber,
                'newVersionAvailable' => $newVersionAvailable,
                'newVersionLink' => $newVersionLink,
                'whatsNew' => $whatsNew,
            )
        );
    } else {
        echo json_encode(
            array(
                'log' => 'Что-то пошло не так при попытке получить актуальную версию с GitHub',
                'newVersionNumber' => '',
                'newVersionAvailable' => false,
                'newVersionLink' => '',
                'whatsNew' => '',
            )
        );
    }
} catch (Exception $e) {
    echo json_encode(
        array(
            'log' => $e->getMessage(),
            'newVersionNumber' => '',
            'newVersionAvailable' => false,
            'newVersionLink' => '',
            'whatsNew' => '',
        )
    );
}