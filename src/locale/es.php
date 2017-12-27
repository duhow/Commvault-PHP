<?php

return array(
    'command' => '<COMANDO>',
    'help_usage' => 'Uso: %s %s',
    'help_client' => 'Muestra información sobre un cliente.',
    'help_clients' => 'Muestra información de varios clientes.',
    'help_clientgroup' => 'Muestra información de un grupo de clientes.',
    'help_clientgroups' => 'Muestra la lista de todos los grupos de clientes.',
    'help_console' => 'Abre una interfaz de consola para ejecutar comandos de Commvault.',
    'help_execute' => 'Ejecuta una tarea programada en un archivo XML.',
    'help_storagepolicy' => 'Muestra la lista de todas las políticas de almacenamiento.',
    'help_library' => 'Muestra las librerías disponibles.',
    'help_backend' => 'Muestra el espacio que están guardando actualmente los clientes.',
    'help_jobs' => 'Muestra las tareas en curso.',
    'help_job' => 'Muestra información de una tarea en concreto.',
    'help_log' => 'Muestra los últimos eventos procesados.',
    'help_login' => 'Inicia sesión y genera un token para realizar consultas.',
    'help_logout' => 'Cierra sesión y revoca el token.',
    'help_ping' => 'Comprueba si un cliente se encuentra disponible.',
    'help_ping_extended' =>
        'Realiza una comprobación de conectividad hacia la máquina.' ."\n"
        .'Especifica nombre o ID de cliente.',
    'error_client_not_found' => 'El nombre de cliente no existe o no se ha encontrado.' ."\n",
    'error_client_no_jobs' => 'No se han encontrado trabajos para ese cliente.' ."\n",
    'error_clientgroup_not_found' => 'El grupo de clientes no existe o no se ha encontrado.' ."\n",
    'error_no_full_backups_found' => 'No se han encontrado backups full.' ."\n",
    'error_token' => 'El token no es válido o ha expirado. Por favor, haz login primero.' ."\n",
    'error_library_exist' => 'La librería no existe.',
    'error_job_action' => 'Ha ocurrido un error al realizar la acción con el trabajo.',
    'error_execute_no_file' => 'Indica el archivo XML con la tarea a ejecutar.' ."\n",
    'error_execute_file_exists' => 'El archivo indicado no existe.' ."\n",
    'error_search_empty' => 'La búsqueda no puede estar vacía.' ."\n",

    'login_reconnect_host' => 'Reutilizando conexión previa:',
    'login_ok' => 'Login correcto, token guardado.',
    'login_connecting' => 'Conectando...',
    'input_username' => 'Usuario:',
    'input_password' => 'Contraseña:',

    'bytes_free' => 'Espacio libre',
    'bytes_used' => 'Espacio en uso',
    'bytes_total' => 'Espacio total',
    'percentage' => 'Porcentaje',

    'clientgroup_processing_amount' => 'Procesando %s clientes ...',
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

    'clients' => 'Clientes',
    'roles' => 'Permisos',
    'lastjob' => 'Ultimo job:',
    'lastbackup' => 'Ultimo backup:',
    'library_backupgiga' => 'GB por 1/24h:',
    'library_freespace' => 'Espacio libre:',

    'execute_jobs_created' => 'Se han creado los jobs:',

    'user_confirm' => 'Por favor, confirma la acción. [S/N] ',
    'user_confirm_yes' => 'si',
    'user_confirm_yes_multi' => ['si', 'ok', 'vale', 'bien', 'yes', 's', 'y'],
    'user_confirm_no' => 'no',
    'user_confirm_no_multi' => ['no', 'nah', 'nop', 'nada', 'n'],
    'unknown_command' => 'Comando desconocido.',

    'job_not_exist' => 'El trabajo indicado no existe.',

    'error_code_2' => 'El cliente no existe.',
    'error_code_3' => 'Conexión fallida.',
);

?>
