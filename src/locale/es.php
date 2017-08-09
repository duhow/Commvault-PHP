<?php

return array(
    'command' => '<COMANDO>',
    'help_usage' => 'Uso: %s %s',
    'help_client' => 'Muestra información sobre un cliente.',
    'help_clients' => 'Muestra información de varios clientes.',
    'help_clientgroup' => 'Muestra información de un grupo de clientes.',
    'help_library' => 'Muestra las librerías disponibles.',
    'help_jobs' => 'Muestra las tareas realizadas.',
    'help_job' => 'Carga información de una tarea en concreto.',
    'help_ping' => 'Permite comprobar que un cliente se encuentra disponible.',
    'help_ping_extended' =>
        'Realiza una comprobación de conectividad hacia la máquina.' ."\n"
        .'Especifica nombre o ID de cliente.',
    'error_client_not_found' => 'El nombre de cliente no existe o no se ha encontrado.' ."\n",
    'error_clientgroup_not_found' => 'El grupo de clientes no existe o no se ha encontrado.' ."\n",
    'error_token' => 'El token no es válido o ha expirado. Por favor, haz login primero.' ."\n",
    'error_library_exist' => 'La librería no existe.',

    'login_reconnect_host' => 'Reutilizando conexión previa:',
    'login_ok' => 'Login correcto, token guardado.',
    'login_connecting' => 'Conectando...',
    'input_username' => 'Usuario:',
    'input_password' => 'Contraseña:',

    'bytes_free' => 'Espacio libre',
    'bytes_used' => 'Espacio en uso',
    'bytes_total' => 'Espacio total',
    'percentage' => 'Porcentaje',

    'client_ping_ok' => 'El cliente %s tiene conexión.',

    'client_displayName' => 'Nombre de cliente',
    'client_hostName' => 'Nombre de host',
    'client_commCellName' => 'CommServe HostName',
    'client_IsVirtualClient' => 'Fisico/Virtual',
    'virtual' => 'Virtual',
    'physical' => 'Fisico',
    'client_OSName' => 'Nombre de SO',
    'client_ProcessorType' => 'Plataforma',
    'client_versionInfo' => 'Version de CommVault',
    'client_clientGroups' => 'Grupos de cliente',

    'lastjob' => 'Ultimo job:',
    'lastbackup' => 'Ultimo backup:',
    'library_backupgiga' => 'GB por 1/24h:',
    'library_freespace' => 'Espacio libre:',

    'job_not_exist' => 'El trabajo indicado no existe.',

    'error_code_2' => 'El cliente no existe.',
    'error_code_3' => 'Conexión fallida.',
);

?>
