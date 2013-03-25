jarumeter
=========

#### CentOS 5.x, from scratch  
    wget http://packages.sw.be/rpmforge-release/rpmforge-release-0.5.2-2.el5.rf.i386.rpm
    sudo rpm -Uvh rpmforge-release-0.5*.rpm
    yum install httpd php php-devel php-pear gcc libssh2-devel

    // install and enable php-libssh2
    pecl install ssh2 channel://pecl.php.net/ssh2-0.12
    touch /etc/php.d/ssh2.ini
    echo extension=ssh2.so > /etc/php.d/ssh2.ini

    // install php-json
    pecl install json
    touch /etc/php.d/json.ini
    echo extension=json.so > /etc/php.d/json.ini

    // optional - create phpinfo.php
    touch /var/www/html/phpinfo.php
    echo \<?php phpinfo\(\)\; ?\> > /var/www/html/phpinfo.php

    service httpd start
