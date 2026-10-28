import { GrowneqHomeLogo } from '@/components/brand/GrowneqLogo';

export function BrandMark({ compact = false }: { compact?: boolean }) {
  return <GrowneqHomeLogo className={compact ? 'h-auto w-32' : 'h-auto w-36 sm:w-40'} priority={!compact} />;
}
