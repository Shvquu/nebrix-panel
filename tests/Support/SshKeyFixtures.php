<?php

declare(strict_types=1);

namespace Nebrix\Tests\Support;

/**
 * Public test keys generated with ssh-keygen (the private keys were discarded)
 * and their fingerprints as printed by "ssh-keygen -l -E sha256".
 */
final class SshKeyFixtures
{
    public const ED25519 = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAICF6DK+q7Gjc24QhMAG9ga8e9HFqpAsssCTdlIlGStym alice@laptop';
    public const ED25519_FP = 'SHA256:EUHf4UPmErxAp4rgkeSjgSbCmWwsTJNWIanaqinx6bk';

    public const ED25519_B = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIAMQYAn6aIUKv4nci1h8bn9Ae3YiUMeUE831ah+tRGO8 bob';
    public const ED25519_B_FP = 'SHA256:LdpBOcpxy3+4VxNKnZnuZ3yNSkR5v2IQA/U2nY3ZKFI';

    public const ECDSA256 = 'ecdsa-sha2-nistp256 AAAAE2VjZHNhLXNoYTItbmlzdHAyNTYAAAAIbmlzdHAyNTYAAABBBNBkJ0Q4+Cs1BMpO5n73ROw8swnArmxWDm45TxKTJ+FeNWxnmYxMdjeoMGvoGiwGcZgndzCpn9SDwjairH/j9Mk= p256';
    public const ECDSA256_FP = 'SHA256:JTou/yS6DHqI6Oz5OGaMcMVqp2kVdaE6oNftdYoZvTw';

    public const ECDSA384 = 'ecdsa-sha2-nistp384 AAAAE2VjZHNhLXNoYTItbmlzdHAzODQAAAAIbmlzdHAzODQAAABhBN3U+LlhiPkXui3QqRLNYNqut58Jt0jhiU3OWTabzJeiOA54V8QhfcWFWnDwx+mjmHyecO5TKVNiVdeltwwxxbPeKkoO9TzmBT6/w7R1M9hqjUGlChQytMpC+1U0DViJnw== ecdsa384';
    public const ECDSA384_FP = 'SHA256:FwHhPY7tXx5sYAunRmbS9XkNflaOR3+Uv/N7xGtV6nQ';

    public const ECDSA521 = 'ecdsa-sha2-nistp521 AAAAE2VjZHNhLXNoYTItbmlzdHA1MjEAAAAIbmlzdHA1MjEAAACFBABZbTMB9nPBbGGJXHql6o6FydH7yEeIsCDfi7JP4aNDeDSxMAH2ATKmbGJXPseggs/ENKd//RL5txzio3INfCMwGgDYrGq3gGmtR6pDv7NLMgFihhi+YgdGdjB9CgHlNh+D/MIoNWqxKifzqq2F3SO0ePEJpEsYBpFit1nFAyQa/Fd9Ww== p521';
    public const ECDSA521_FP = 'SHA256:/BNe4PX/SyQ2+aCpZRroURT00Dyum2Ns8v3tfG96TvA';

    public const RSA2048 = 'ssh-rsa AAAAB3NzaC1yc2EAAAADAQABAAABAQC/8i/hrxXZbgBBNkOnzxnYkUqM9BuuAu9qbIRN5MHIuCxUhfgju53pNQ/ecPKoe6i8xzeCiDw6dJf35zh1rMcw9hRPqMd6IFk4/DuKPf+ZT6El9xydScyGcjar9nFKFHDZza83UZvfuAHSVm//pN/YDJzpsYegHlM8QUyneXp0Gvb8zOcWXy/0rQoRXyi6N3cVTuTCYF+MS3pklR3cSvq85NQ/yGLveq6Qlm0/65H8YQ/8+T5H/h8x3ZO7xks4zJOB57qhvuEvdUCp8XqYLpFSBgbZubWtRdpeJG4p9g9CFXrJntvgCTZyhRwc37mCG/DbB+CsQSeKorOgmQ7JbWFL rsa2048';
    public const RSA2048_FP = 'SHA256:xQc30wvVQynPSdvPcVrX0otI1ZddCYC9Lz1AQaFJpII';

    public const RSA1024 = 'ssh-rsa AAAAB3NzaC1yc2EAAAADAQABAAAAgQC7LUNtKdLBp8CQE/RRtOaTcK1bgW/+COB9rzfp4V0VRpf7Aa9BX8DXgm3np9TpWOqLdHpswNA8BXWoFyTOLo2p4OzrywYqgD02WixzmUKIo+6Ikas1SUItYhDzn4MPrS5h0k35aEgRxHHC9NlJ4tUWjV8qZGAAkbAGK4t+maTV0Q== rsa1024';
}
