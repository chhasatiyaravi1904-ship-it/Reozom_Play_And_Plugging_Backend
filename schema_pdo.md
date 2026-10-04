# Database Schema (reozom_play_plugin)

## Table: `admin_activity_logs`

| Column | Data Type | Nullable | Key | Default | Extra |
|---|---|---|---|---|---|
| id | bigint | NO | PRI | NULL | auto_increment |
| user_id | bigint | YES | MUL | NULL |  |
| action | varchar | NO |  | NULL |  |
| description | text | YES |  | NULL |  |
| ip | varchar | YES |  | NULL |  |
| created_at | timestamp | YES |  | NULL |  |
| updated_at | timestamp | YES |  | NULL |  |

## Table: `agent_packages`

| Column | Data Type | Nullable | Key | Default | Extra |
|---|---|---|---|---|---|
| id | char | NO | PRI | NULL |  |
| user_id | bigint | NO | MUL | NULL |  |
| package_id | char | NO | MUL | NULL |  |
| started_at | timestamp | YES |  | NULL |  |
| expires_at | timestamp | YES |  | NULL |  |
| created_at | timestamp | YES |  | NULL |  |
| updated_at | timestamp | YES |  | NULL |  |

## Table: `cache`

| Column | Data Type | Nullable | Key | Default | Extra |
|---|---|---|---|---|---|
| key | varchar | NO | PRI | NULL |  |
| value | mediumtext | NO |  | NULL |  |
| expiration | bigint | NO | MUL | NULL |  |

## Table: `cache_locks`

| Column | Data Type | Nullable | Key | Default | Extra |
|---|---|---|---|---|---|
| key | varchar | NO | PRI | NULL |  |
| owner | varchar | NO |  | NULL |  |
| expiration | bigint | NO | MUL | NULL |  |

## Table: `cities`

| Column | Data Type | Nullable | Key | Default | Extra |
|---|---|---|---|---|---|
| id | char | NO | PRI | NULL |  |
| name | varchar | NO |  | NULL |  |
| slug | varchar | NO |  | NULL |  |
| county_id | char | NO | MUL | NULL |  |
| code | varchar | NO |  | NULL |  |
| is_active | tinyint | NO |  | 1 |  |
| created_at | timestamp | YES |  | NULL |  |
| updated_at | timestamp | YES |  | NULL |  |

## Table: `counties`

| Column | Data Type | Nullable | Key | Default | Extra |
|---|---|---|---|---|---|
| id | char | NO | PRI | NULL |  |
| name | varchar | NO |  | NULL |  |
| code | varchar | NO |  | NULL |  |
| state_id | char | NO | MUL | NULL |  |
| slug | varchar | NO |  | NULL |  |
| is_active | tinyint | NO |  | 1 |  |
| created_at | timestamp | YES |  | NULL |  |
| updated_at | timestamp | YES |  | NULL |  |

## Table: `failed_jobs`

| Column | Data Type | Nullable | Key | Default | Extra |
|---|---|---|---|---|---|
| id | bigint | NO | PRI | NULL | auto_increment |
| uuid | varchar | NO | UNI | NULL |  |
| connection | varchar | NO | MUL | NULL |  |
| queue | varchar | NO |  | NULL |  |
| payload | longtext | NO |  | NULL |  |
| exception | longtext | NO |  | NULL |  |
| failed_at | timestamp | NO |  | current_timestamp() |  |

## Table: `jobs`

| Column | Data Type | Nullable | Key | Default | Extra |
|---|---|---|---|---|---|
| id | bigint | NO | PRI | NULL | auto_increment |
| queue | varchar | NO | MUL | NULL |  |
| payload | longtext | NO |  | NULL |  |
| attempts | smallint | NO |  | NULL |  |
| reserved_at | int | YES |  | NULL |  |
| available_at | int | NO |  | NULL |  |
| created_at | int | NO |  | NULL |  |

## Table: `job_batches`

| Column | Data Type | Nullable | Key | Default | Extra |
|---|---|---|---|---|---|
| id | varchar | NO | PRI | NULL |  |
| name | varchar | NO |  | NULL |  |
| total_jobs | int | NO |  | NULL |  |
| pending_jobs | int | NO |  | NULL |  |
| failed_jobs | int | NO |  | NULL |  |
| failed_job_ids | longtext | NO |  | NULL |  |
| options | mediumtext | YES |  | NULL |  |
| cancelled_at | int | YES |  | NULL |  |
| created_at | int | NO |  | NULL |  |
| finished_at | int | YES |  | NULL |  |

## Table: `listings`

| Column | Data Type | Nullable | Key | Default | Extra |
|---|---|---|---|---|---|
| id | bigint | NO | PRI | NULL | auto_increment |
| user_id | bigint | NO | MUL | NULL |  |
| reference_code | varchar | NO | UNI | NULL |  |
| status | varchar | NO |  | 'in_progress' |  |
| address | varchar | NO |  | NULL |  |
| city | varchar | NO |  | NULL |  |
| state | varchar | NO |  | NULL |  |
| zip | varchar | NO |  | NULL |  |
| steps_completed | smallint | NO |  | 0 |  |
| steps_total | smallint | NO |  | 4 |  |
| created_at | timestamp | YES |  | NULL |  |
| updated_at | timestamp | YES |  | NULL |  |
| service_package_id | bigint | YES | MUL | NULL |  |
| listing_process_id | bigint | YES | MUL | NULL |  |
| workflow_snapshot | longtext | YES |  | NULL |  |

## Table: `listing_answers`

| Column | Data Type | Nullable | Key | Default | Extra |
|---|---|---|---|---|---|
| id | bigint | NO | PRI | NULL | auto_increment |
| listing_id | bigint | NO | MUL | NULL |  |
| step_id | varchar | NO |  | NULL |  |
| values | longtext | YES |  | NULL |  |
| created_at | timestamp | YES |  | NULL |  |
| updated_at | timestamp | YES |  | NULL |  |

## Table: `listing_processes`

| Column | Data Type | Nullable | Key | Default | Extra |
|---|---|---|---|---|---|
| id | bigint | NO | PRI | NULL | auto_increment |
| name | varchar | NO |  | NULL |  |
| type | varchar | NO |  | 'custom' |  |
| status | varchar | NO |  | 'draft' |  |
| agent_id | bigint | YES | MUL | NULL |  |
| service_package_id | bigint | YES | MUL | NULL |  |
| assigned_zips | longtext | YES |  | NULL |  |
| config | longtext | YES |  | NULL |  |
| created_at | timestamp | YES |  | NULL |  |
| updated_at | timestamp | YES |  | NULL |  |

## Table: `migrations`

| Column | Data Type | Nullable | Key | Default | Extra |
|---|---|---|---|---|---|
| id | int | NO | PRI | NULL | auto_increment |
| migration | varchar | NO |  | NULL |  |
| batch | int | NO |  | NULL |  |

## Table: `mls_directories`

| Column | Data Type | Nullable | Key | Default | Extra |
|---|---|---|---|---|---|
| id | bigint | NO | PRI | NULL | auto_increment |
| title | text | NO |  | NULL |  |
| created_at | timestamp | YES |  | NULL |  |
| updated_at | timestamp | YES |  | NULL |  |

## Table: `mls_infos`

| Column | Data Type | Nullable | Key | Default | Extra |
|---|---|---|---|---|---|
| id | bigint | NO | PRI | NULL | auto_increment |
| mls_directory_id | bigint | NO | MUL | NULL |  |
| title | text | YES |  | NULL |  |
| countries | longtext | YES |  | NULL |  |
| public_websites_title | text | YES |  | NULL |  |
| websites | longtext | YES |  | NULL |  |
| info | text | YES |  | NULL |  |
| created_at | timestamp | YES |  | NULL |  |
| updated_at | timestamp | YES |  | NULL |  |

## Table: `model_has_permissions`

| Column | Data Type | Nullable | Key | Default | Extra |
|---|---|---|---|---|---|
| permission_id | bigint | NO | PRI | NULL |  |
| model_type | varchar | NO | PRI | NULL |  |
| model_id | bigint | NO | PRI | NULL |  |

## Table: `model_has_roles`

| Column | Data Type | Nullable | Key | Default | Extra |
|---|---|---|---|---|---|
| role_id | bigint | NO | PRI | NULL |  |
| model_type | varchar | NO | PRI | NULL |  |
| model_id | bigint | NO | PRI | NULL |  |

## Table: `packages`

| Column | Data Type | Nullable | Key | Default | Extra |
|---|---|---|---|---|---|
| id | char | NO | PRI | NULL |  |
| name | varchar | NO |  | NULL |  |
| slug | varchar | NO | UNI | NULL |  |
| role_name | varchar | NO | UNI | NULL |  |
| description | text | YES |  | NULL |  |
| price | decimal | YES |  | NULL |  |
| duration_days | int | NO |  | 30 |  |
| max_listing_processes | int | YES |  | NULL |  |
| sort_order | int | NO |  | 0 |  |
| is_active | tinyint | NO |  | 1 |  |
| created_at | timestamp | YES |  | NULL |  |
| updated_at | timestamp | YES |  | NULL |  |

## Table: `package_activity_logs`

| Column | Data Type | Nullable | Key | Default | Extra |
|---|---|---|---|---|---|
| id | char | NO | PRI | NULL |  |
| user_id | bigint | YES | MUL | NULL |  |
| package_id | char | YES | MUL | NULL |  |
| previous_package_id | char | YES | MUL | NULL |  |
| action | varchar | NO |  | NULL |  |
| ip | varchar | YES |  | NULL |  |
| created_at | timestamp | YES |  | NULL |  |
| updated_at | timestamp | YES |  | NULL |  |

## Table: `password_reset_tokens`

| Column | Data Type | Nullable | Key | Default | Extra |
|---|---|---|---|---|---|
| email | varchar | NO | PRI | NULL |  |
| token | varchar | NO |  | NULL |  |
| created_at | timestamp | YES |  | NULL |  |

## Table: `permissions`

| Column | Data Type | Nullable | Key | Default | Extra |
|---|---|---|---|---|---|
| id | bigint | NO | PRI | NULL | auto_increment |
| name | varchar | NO | MUL | NULL |  |
| guard_name | varchar | NO |  | NULL |  |
| created_at | timestamp | YES |  | NULL |  |
| updated_at | timestamp | YES |  | NULL |  |

## Table: `personal_access_tokens`

| Column | Data Type | Nullable | Key | Default | Extra |
|---|---|---|---|---|---|
| id | bigint | NO | PRI | NULL | auto_increment |
| tokenable_type | varchar | NO | MUL | NULL |  |
| tokenable_id | bigint | NO |  | NULL |  |
| name | text | NO |  | NULL |  |
| token | varchar | NO | UNI | NULL |  |
| abilities | text | YES |  | NULL |  |
| last_used_at | timestamp | YES |  | NULL |  |
| expires_at | timestamp | YES | MUL | NULL |  |
| created_at | timestamp | YES |  | NULL |  |
| updated_at | timestamp | YES |  | NULL |  |

## Table: `roles`

| Column | Data Type | Nullable | Key | Default | Extra |
|---|---|---|---|---|---|
| id | bigint | NO | PRI | NULL | auto_increment |
| name | varchar | NO | MUL | NULL |  |
| guard_name | varchar | NO |  | NULL |  |
| created_at | timestamp | YES |  | NULL |  |
| updated_at | timestamp | YES |  | NULL |  |

## Table: `role_has_permissions`

| Column | Data Type | Nullable | Key | Default | Extra |
|---|---|---|---|---|---|
| permission_id | bigint | NO | PRI | NULL |  |
| role_id | bigint | NO | PRI | NULL |  |

## Table: `service_packages`

| Column | Data Type | Nullable | Key | Default | Extra |
|---|---|---|---|---|---|
| id | bigint | NO | PRI | NULL | auto_increment |
| agent_id | bigint | NO | MUL | NULL |  |
| name | varchar | NO |  | NULL |  |
| description | text | YES |  | NULL |  |
| price | decimal | YES |  | NULL |  |
| is_active | tinyint | NO |  | 1 |  |
| created_at | timestamp | YES |  | NULL |  |
| updated_at | timestamp | YES |  | NULL |  |

## Table: `service_package_zip_code`

| Column | Data Type | Nullable | Key | Default | Extra |
|---|---|---|---|---|---|
| id | bigint | NO | PRI | NULL | auto_increment |
| service_package_id | bigint | NO | MUL | NULL |  |
| zip_code_id | char | NO | MUL | NULL |  |

## Table: `sessions`

| Column | Data Type | Nullable | Key | Default | Extra |
|---|---|---|---|---|---|
| id | varchar | NO | PRI | NULL |  |
| user_id | bigint | YES | MUL | NULL |  |
| ip_address | varchar | YES |  | NULL |  |
| user_agent | text | YES |  | NULL |  |
| payload | longtext | NO |  | NULL |  |
| last_activity | int | NO | MUL | NULL |  |

## Table: `social_accounts`

| Column | Data Type | Nullable | Key | Default | Extra |
|---|---|---|---|---|---|
| id | bigint | NO | PRI | NULL | auto_increment |
| user_id | bigint | NO | MUL | NULL |  |
| provider | varchar | NO | MUL | NULL |  |
| provider_user_id | varchar | NO |  | NULL |  |
| created_at | timestamp | YES |  | NULL |  |
| updated_at | timestamp | YES |  | NULL |  |

## Table: `states`

| Column | Data Type | Nullable | Key | Default | Extra |
|---|---|---|---|---|---|
| id | char | NO | PRI | NULL |  |
| name | varchar | NO |  | NULL |  |
| slug | varchar | NO | UNI | NULL |  |
| code | varchar | NO | UNI | NULL |  |
| is_active | tinyint | NO |  | 1 |  |
| created_at | timestamp | YES |  | NULL |  |
| updated_at | timestamp | YES |  | NULL |  |

## Table: `users`

| Column | Data Type | Nullable | Key | Default | Extra |
|---|---|---|---|---|---|
| id | bigint | NO | PRI | NULL | auto_increment |
| name | varchar | NO |  | NULL |  |
| first_name | varchar | YES |  | NULL |  |
| last_name | varchar | YES |  | NULL |  |
| email | varchar | NO | UNI | NULL |  |
| phone | varchar | YES |  | NULL |  |
| street_address | varchar | YES |  | NULL |  |
| city | varchar | YES |  | NULL |  |
| state | varchar | YES |  | NULL |  |
| zip | varchar | YES |  | NULL |  |
| company | varchar | YES |  | NULL |  |
| office_number | varchar | YES |  | NULL |  |
| extension | varchar | YES |  | NULL |  |
| profile_finished | tinyint | NO |  | 0 |  |
| is_active | tinyint | NO |  | 1 |  |
| role | enum | NO |  | 'buyer' |  |
| email_verified_at | timestamp | YES |  | NULL |  |
| password | varchar | NO |  | NULL |  |
| remember_token | varchar | YES |  | NULL |  |
| created_at | timestamp | YES |  | NULL |  |
| updated_at | timestamp | YES |  | NULL |  |
| two_factor_code | varchar | YES |  | NULL |  |
| two_factor_expires_at | datetime | YES |  | NULL |  |

## Table: `user_login_logs`

| Column | Data Type | Nullable | Key | Default | Extra |
|---|---|---|---|---|---|
| id | bigint | NO | PRI | NULL | auto_increment |
| user_id | bigint | NO | MUL | NULL |  |
| login_type | varchar | NO |  | 'direct' |  |
| device | varchar | YES |  | NULL |  |
| platform | varchar | YES |  | NULL |  |
| platform_version | varchar | YES |  | NULL |  |
| browser | varchar | YES |  | NULL |  |
| browser_version | varchar | YES |  | NULL |  |
| ip | varchar | YES |  | NULL |  |
| created_at | timestamp | YES |  | NULL |  |
| updated_at | timestamp | YES |  | NULL |  |

## Table: `zip_codes`

| Column | Data Type | Nullable | Key | Default | Extra |
|---|---|---|---|---|---|
| id | char | NO | PRI | NULL |  |
| code | varchar | NO | UNI | NULL |  |
| state_id | char | NO | MUL | NULL |  |
| county_id | char | NO | MUL | NULL |  |
| city_id | char | NO | MUL | NULL |  |
| is_active | tinyint | NO | MUL | 1 |  |
| created_at | timestamp | YES |  | NULL |  |
| updated_at | timestamp | YES |  | NULL |  |

