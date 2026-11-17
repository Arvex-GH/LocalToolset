<?php
/**
 * Copy this file to bridge.secrets.php and fill in the database details.
 *
 * Put it one level ABOVE the web root when you can — for a typical shared host
 * that means next to the htdocs folder, not inside it, so the web server can
 * never hand it out even if PHP stops executing. setup.php prints the exact
 * path it is using.
 *
 * Never commit your filled-in copy.
 */

return array(
    // The MySQL database from the control panel. The host is usually not
    // "localhost" on shared hosting: it looks like sql123.infinityfree.com.
    'db' => array(
        'host' => 'sqlXXX.infinityfree.com',
        'user' => 'if0_XXXXXXXX',
        'pass' => 'the database password',
        'name' => 'if0_XXXXXXXX_bridge',
        'port' => 3306,
    ),

    // The directory the agent may read and write, and everything it may not
    // escape from. Defaults to the folder holding these files.
    'root' => __DIR__ . '/htdocs',

    // Where job ticks and their output are stored. Keep it out of the web root
    // if you can: the files contain the code an agent asked to run.
    'state_dir' => __DIR__ . '/bridge_state',

    // op=php runs arbitrary PHP: powerful for the agent, and the biggest risk
    // here. Leave it false unless you actually want code execution.
    'allow_php' => false,

    // Databases the agent may query with op=sql, by name. The panel's own
    // database is fair game once you list it; omit it to keep the panel out of
    // reach. Credentials stay in this file, never in a URL.
    'databases' => array(
        'main' => array(
            'host' => 'sqlXXX.infinityfree.com',
            'user' => 'if0_XXXXXXXX',
            'pass' => 'the database password',
            'name' => 'if0_XXXXXXXX_site',
        ),
    ),
);
