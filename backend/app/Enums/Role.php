<?php

namespace App\Enums;

enum Role: string
{
    case Customer = 'nasabah';
    case Verifier = 'verifikator';
    case ApproverOne = 'penyetuju_1';
    case ApproverTwo = 'penyetuju_2';
    case Admin = 'admin';
}
