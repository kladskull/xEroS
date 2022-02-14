<?php declare(strict_types=1);

namespace Xeros;

enum TransactionVersion
{
    case Coinbase;
    case Transfer;
}
