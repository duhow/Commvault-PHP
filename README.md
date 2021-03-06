# Commvault-PHP
Ejecuta comandos en **Commvault** usando REST API.

Los comandos disponibles actualmente son:

```
commvault console

commvault login HOSTNAME
          login USERNAME PASSWORD HOSTNAME
          logout

commvault clients               [ id | name | csv | json ]
          clients backend       [ text | csv | total ]
          clients assoc         [ text | id | csv | json ]

commvault client [ list | all ] [ id | name | csv | json ]
          client MYSERVER id
          client MYSERVER status
          client MYSERVER size
          client MYSERVER backend
          client MYSERVER [ jobs | lastjob | lastfull ]
          client MYSERVER [ xml | json ]

commvault ping             [ MYSERVER   | id ]
          ping clientgroup [ MYCLIGROUP | id ]

commvault clientgroups                   [ id | name | csv | json ]

commvault clientgroup MYCLIGROUP clients [ id | name | csv | json ]
          clientgroup MYCLIGROUP proxies [ id | name | csv | json ]
          clientgroup MYCLIGROUP size    [ csv | json ]
          clientgroup MYCLIGROUP backend [ csv | total | text ]

commvault storagepolicy            [ id | name | csv | json ]
          storagepolicy MEDIAAGENT [ id | name | csv | json ]

commvault job JOBID [ xml ]
          job JOBID [ kill | pause | resume ]
          jobs      [ kill | pause | resume ]
          jobs      [ summary ]

commvault log          [ info | minor | major | critical ]
          log MYSERVER [ info | minor | major | critical ]
          log lastid   [ info | minor | major | critical ]
          log monitor
          log full

commvault library
          library MYLIB  [ xml | size | jobs ]
          library sizes  [ text | bar | csv | json ]
          library drives [ text | bar | csv | json ]

commvault backend [ text | csv | json ]
```
