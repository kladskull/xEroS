# Xeros

[![Software License][ico-license]](LICENSE.md)

Xeros is a digital currency that allows instant payments to anyone, anywhere. Xeros has been written completely in PHP 
and follows the same technical design as Bitcoin. Xeros uses P2P technology to operate with no central server.

## Install
```
apt update && apt upgrade
apt install software-properties-common
add-apt-repository ppa:ondrej/php
apt update
apt install -y php8.1 php8.1-common php8.1-mysql php8.1-xml php8.1-xmlrpc php8.1-curl php8.1-cli php8.1-mbstring php8.1-bcmath unzip php8.1-zip mariadb-server mariadb-client apache2
a2enmod rewrite
systemctl restart apache2
git config --global user.email "mikecurry74@gmail.com"
git config --global user.name "Mike Curry"

(for maintainer)
ssh-keygen -o
cat ~/.ssh/id_rsa.pub
git clone git@github.com:bulletchain-os/bchain.git
```

## Change log

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Testing

## Todo

## Credits

- Mike Curry <mikecurry74@gmail.com>
- ## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

[ico-license]: https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square
