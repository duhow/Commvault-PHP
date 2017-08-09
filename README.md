# Commvault-PHP
Ejecuta comandos en **Commvault** usando REST API.

Los comandos disponibles actualmente son:

```
commvault login HOSTNAME
          login USERNAME PASSWORD HOSTNAME
          logout

commvault client [ list | all ] [ id | name | csv | json ]
          client MYSERVER id
          client MYSERVER status
          client MYSERVER [ jobs | lastjob ]
          client MYSERVER [ xml | json | csv ]

commvault ping [ MYSERVER | id ]

commvault clientgroup MYCLIGROUP
          clientgroups [ id | name | csv | json ]

commvault storagepolicy

commvault job JOBID

commvault library
          library MYLIB [ size ]
          library sizes [ text | bar | csv | json ]
```
