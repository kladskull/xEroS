# Xeros

![GitHub](https://img.shields.io/github/license/kladskull/xeros?style=flat)
![Discord](https://img.shields.io/discord/948379180393984062?style=flat)
[![Build Status](https://app.travis-ci.com/kladskull/xeros.svg?branch=main)](https://app.travis-ci.com/kladskull/xeros)
[![Code Intelligence Status](https://scrutinizer-ci.com/g/kladskull/xeros/badges/code-intelligence.svg?b=main)](https://scrutinizer-ci.com/code-intelligence)
![GitHub Sponsors](https://img.shields.io/github/sponsors/kladskull?style=flat)

Xeros is a digital currency that allows instant payments to anyone, anywhere. Xeros has been written completely in PHP
and mostly follows the technical design of Bitcoin. Xeros uses P2P technology to operate with no central server. 

There are many differences to Bitcoin, such as the scripting language which is "XeroASM" which is a really dumbed down 
version of assembler - one thing I'd like to do is offer easier scripting languages that "compile" down to the XeroASM.

## Contact
You can join the [Xeros Discord server](https://discord.gg/mvacfndPXt), or email me: kladskull@protonmail.com.

## Status of the Project (Yes it's active!)
Currently, the blockchain has not launched - it is however close to its first release version. I plan on launching it
in the next month or two - once I have some of the higher priority tasks are done in the Github issues area of this 
project.

## Install
```
apt update -y && apt upgrade -y
apt install -y software-properties-common
add-apt-repository -y ppa:ondrej/php
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

## Xeros Sponsors
### Bronze Supporters
- [Travis](https://www.travis-ci.com/) 
- [Scrutinizer](https://scrutinizer-ci.com/)

If you are looking to sponsor, fund or donate, please see the Support & Funding section below.

## Contributing
The codebase is maintained using the "contributor workflow" where everyone without exception contributes patch proposals using "pull requests" (PRs). This facilitates social contribution, easy testing and peer review.

To contribute a patch, the workflow is as follows:

1. Fork repository (only for the first time)
2. Create topic branch
3. Commit patches

New contributors are always welcome and needed.

### Creating the Pull Request
The title of the pull request should be prefixed by the component or area that the pull request affects. Valid areas as:

```consensus``` for changes to consensus critical code

```doc``` for changes to the documentation

```log``` for changes to log messages

```mining``` for changes to the mining code

```p2p``` for changes to the peer-to-peer network code

```refactor``` for structural changes that do not change behavior

```script``` for changes to the scripts and tools

```test```, ```qa``` or ```ci``` for changes to the unit tests, QA tests or CI code

```util``` or ```lib``` for changes to the utils or libraries

```wallet``` for changes to the wallet code

Examples:
```consensus: Add new opcode for BIP-XXXX OP_CHECKAWESOMESIG
p2p: Automatically create onion service, listen on Tor
script: Add feed bump button
log: Fix typo in log message
```

### Copyright
By contributing to this repository, you agree to license your work under the MIT license unless specified otherwise 
in contrib/debian/copyright or at the top of the file itself. Any work contributed where you are not the original 
author must contain its license header with the original author(s) and source.

### Core Development Discussion
You may propose new features or improvements of existing Xeros behavior in the GitHub repository 
discussion board. If you propose a new feature, please be willing to implement at least some of the code that would be 
needed to complete the feature.

### Which Branch?
All bug fixes should be sent to the latest stable branch. Bug fixes should never be sent to the master branch unless 
they fix features that exist only in the upcoming release.

Minor features that are fully backward compatible with the current release may be sent to the latest stable branch.

Major new features should always be sent to the master branch, which contains the upcoming release.

If you are unsure if your feature qualifies as a major or minor, please ask Kladskull in the #development channel 
of the [Xeros Discord server](https://discord.gg/mvacfndPXt). We are sending most git activity to this discord.

### Security Vulnerabilities
If you discover a security vulnerability within Xeros, please send an email to Kladskull at kladskull@protonmail.com. 
All security vulnerabilities will be promptly addressed.

### Coding Style
Xeros uses the PSR-2 coding standard and the PSR-4 autoloading standard.

### Credits section
Kladskull will update the credits section. Credits is for long term consistant contributers, one off contributions likely
will not be credited.

## Credits
- Kladskull <kladskull@protonmail.com>

## Support & Funding
I wanted to learn how blockchains worked, and the only way was to actually sit down, research and finally - just start.
I am not doing this for any monetary gain, however, if you would like to show support for this project, please use the addresses below, become a [Patreon](https://www.patreon.com/user?u=32938240) or [buy me a coffee](https://www.buymeacoffee.com/kladskull).   
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
