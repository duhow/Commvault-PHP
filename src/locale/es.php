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
    'error_token' => 'El token no es válido o ha expirado. Por favor, haz login primero.' ."\n",

    'input_username' => 'Usuario:',
    'input_password' => 'Contraseña:',

    'client_ping_ok' => 'El cliente %s tiene conexión.',

    'error_code_2' => 'El cliente no existe.',
    'error_code_3' => 'Conexión fallida.',
);

?>
