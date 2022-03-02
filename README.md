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
apt install -y unzip curl 
apt install -y php8.1 php8.1-common php8.1-sqlite3 php8.1-xml php8.1-xmlrpc php8.1-curl php8.1-cli php8.1-mbstring php8.1-bcmath php8.1-zip
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
php -r "if (hash_file('sha384', 'composer-setup.php') === '906a84df04cea2aa72f40b5f787e49f22d4c2f19492ac310e8cba5b96ac8b64115ac402c8cd292b8a03482574915d1a8') { echo 'Installer verified'; } else { echo 'Installer corrupt'; unlink('composer-setup.php'); } echo PHP_EOL;"
php composer-setup.php
php -r "unlink('composer-setup.php');"
sudo mv composer.phar /usr/local/bin/composer
cd xeros
cp .env_sample .env
composer install
./phinx migrate
```

## Change log

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Testing

## Todo

## Credits

- Mike Curry <mikecurry74@gmail.com>
## Support & Funding
If you are interested in showing support for this project, please use the addresses below, or become a [Patreon](https://www.patreon.com/user?u=32938240).   
```
BTC: bc1q9svdtcr8cuvr9qfv7ha6c4femvnsjj5vfp2fes
ETH: 0xd070069f968aD9C2e49C8E04d27006dc0C9c1543
LTC: ltc1q3prtjr00mphqcqtsqxmy6wsyplwatrkd3668qq
Doge: DMiAdu7YWmNcRtyDXNcB6j17iV7gFPceSX
XEROS: Address will be here once it's live
```
## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

[ico-license]: https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square
