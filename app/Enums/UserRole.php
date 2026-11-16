<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Agent = 'agent';
    case SellerBuyer = 'seller_buyer';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::Agent => 'Licensed Agent',
            self::SellerBuyer => 'Seller / Buyer',
        };
    }

    public static function fromValue(mixed $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return self::SellerBuyer;
        }

        $normalized = strtolower(trim($value));
        $normalized = str_replace([' ', '/', '-'], '_', $normalized);

        return match ($normalized) {
            'admin' => self::Admin,
            'agent' => self::Agent,
            'seller_buyer', 'buyer_seller', 'seller', 'buyer', 'client' => self::SellerBuyer,
            default => self::tryFrom($value) ?? self::SellerBuyer,
        };
    }
}
