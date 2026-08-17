<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Agent = 'agent';
    case Seller = 'seller';
    case Buyer = 'buyer';
}
