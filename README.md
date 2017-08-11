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
          client MYSERVER size
          client MYSERVER [ jobs | lastjob ]
          client MYSERVER [ xml | json ]

commvault ping             [ MYSERVER   | id ]
          ping clientgroup [ MYCLIGROUP | id ]

commvault clientgroups                   [ id | name | csv | json ]

commvault clientgroup MYCLIGROUP clients [ id | name | csv | json ]
          clientgroup MYCLIGROUP proxies [ id | name | csv | json ]
          clientgroup MYCLIGROUP size    [ csv | json ]

commvault storagepolicy            [ id | name | csv | json ]
          storagepolicy MEDIAAGENT [ id | name | csv | json ]

commvault job JOBID
          jobs [ summary ]

commvault log          [ info | minor | major | critical ]
          log MYSERVER [ info | minor | major | critical ]
          log lastid   [ info | minor | major | critical ]
          log full

commvault library
          library MYLIB [ xml | size | jobs ]
          library sizes [ text | bar | csv | json ]
```
