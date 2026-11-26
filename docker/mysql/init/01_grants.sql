-- Grant diagnostic privileges to lab_user so performance_schema.data_locks,
-- SHOW ENGINE INNODB STATUS, and mysql.slow_log can be inspected from PHP/CLI.
GRANT PROCESS ON *.* TO 'lab_user'@'%';
GRANT REPLICATION CLIENT ON *.* TO 'lab_user'@'%';
GRANT SELECT ON performance_schema.* TO 'lab_user'@'%';
GRANT SELECT ON mysql.slow_log TO 'lab_user'@'%';
FLUSH PRIVILEGES;
