<?php
if (extension_loaded('memcached')) {
    $cache = new \Memcached();
    $cache->addServer('localhost', 11222, true);

    ////////////////////
    //for staging only//
    ///////////////////
    $cache->get("@@test_admin_kv_data");
    $key_prefix = 'staging_';

    ///////////////////////
    //for production only//
    //////////////////////
    //$cache->get("@@admin_kv_data");
    //$key_prefix = '';
}

function get_cost_appr_settings() {
    if (extension_loaded('memcached')) {
        $cache = $GLOBALS['cache'];
        $key_prefix = $GLOBALS['key_prefix'];
        $auto_approve = intval($cache->get($key_prefix . 'auto_approve'));
        $auto_cost = intval($cache->get($key_prefix . 'auto_cost'));
    } else {
        $costApprQry = "SELECT * from `admin_settings`";
        $result = mysqli_query($GLOBALS['db'], $costApprQry);
        $costApprSettings = mysqli_fetch_assoc($result);
        $auto_approve = intval($costApprSettings['auto_approve']);
        $auto_cost = intval($costApprSettings['auto_cost']);
    }
    return array(
        'auto_approve' => $auto_approve,
        'auto_cost' => $auto_cost,
    );
}
