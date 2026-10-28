import Image from 'next/image';
import Link from 'next/link';

type LogoVariant = 'horizontal' | 'icon' | 'dark-icon';

const LOGOS = {
  horizontal: {
    src: '/branding/growneq-horizontal-logo.png',
    width: 915,
    height: 260,
  },
  icon: {
    src: '/branding/growneq-meta-app-icon-1024.png',
    width: 1024,
    height: 1024,
  },
  'dark-icon': {
    src: '/branding/growneq-dark-app-icon-1024.png',
    width: 1024,
    height: 1024,
  },
} as const;

export function GrowneqLogo({
  variant = 'horizontal',
  className = '',
  priority = false,
}: {
  variant?: LogoVariant;
  className?: string;
  priority?: boolean;
}) {
  const logo = LOGOS[variant];

  return (
    <Image
      src={logo.src}
      width={logo.width}
      height={logo.height}
      alt="Growneq CRM"
      className={`object-contain ${className}`}
      priority={priority}
      sizes={variant === 'horizontal' ? '(max-width: 640px) 128px, 156px' : '40px'}
    />
  );
}

export function GrowneqHomeLogo({
  className = 'h-auto w-36',
  priority = false,
}: {
  className?: string;
  priority?: boolean;
}) {
  return (
    <Link href="/" className="inline-flex shrink-0" aria-label="Growneq CRM home">
      <GrowneqLogo className={className} priority={priority} />
    </Link>
  );
}

export function AuthBrand() {
  return (
    <div className="mb-7 flex justify-center">
      <GrowneqHomeLogo className="h-auto w-44 max-w-full" priority />
    </div>
  );
}
