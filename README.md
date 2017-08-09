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
          client MYSERVER [ xml | json ]

commvault ping             [ MYSERVER   | id ]
          ping clientgroup [ MYCLIGROUP | id ]

commvault clientgroup MYCLIGROUP [ clients | proxies ] [ id | name | csv | json ]
          clientgroups             [ id | name | csv | json ]

commvault storagepolicy            [ id | name | csv | json ]
          storagepolicy MEDIAAGENT [ id | name | csv | json ]

commvault job JOBID
          jobs

commvault library
          library MYLIB [ size ]
          library sizes [ text | bar | csv | json ]
```
