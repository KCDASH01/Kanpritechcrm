// Shimmer skeleton components — all use the `.skeleton` utility class from globals.css

export function SkeletonLine({ className = '' }: { className?: string }) {
  return <div className={`skeleton rounded-lg h-4 ${className}`} />;
}

export function SkeletonAvatar({ size = 8 }: { size?: number }) {
  return (
    <div
      className="skeleton rounded-full shrink-0"
      style={{ width: size * 4, height: size * 4 }}
    />
  );
}

export function SkeletonBadge() {
  return <div className="skeleton rounded-full h-5 w-16" />;
}

/** Full card placeholder — mirrors a stat card */
export function SkeletonCard() {
  return (
    <div className="bg-white rounded-2xl border border-gray-100 p-5 space-y-3 overflow-hidden">
      <SkeletonLine className="w-1/2" />
      <SkeletonLine className="w-1/4 h-8" />
      <SkeletonLine className="w-1/3 h-3" />
    </div>
  );
}

/** Table rows placeholder */
export function SkeletonTable({ rows = 5, cols = 6 }: { rows?: number; cols?: number }) {
  return (
    <div className="divide-y divide-gray-50">
      {Array.from({ length: rows }).map((_, i) => (
        <div key={i} className="flex items-center gap-4 px-5 py-3.5">
          <SkeletonAvatar size={8} />
          <div className="flex-1 space-y-2">
            <SkeletonLine className="w-32" />
            <SkeletonLine className="w-20 h-3" />
          </div>
          {Array.from({ length: cols - 2 }).map((_, j) => (
            <SkeletonLine key={j} className="w-20 hidden sm:block" />
          ))}
          <SkeletonBadge />
        </div>
      ))}
    </div>
  );
}

/** Activity card row placeholder */
export function SkeletonActivityCard() {
  return (
    <div className="bg-white rounded-2xl border border-gray-100 p-4 flex gap-4">
      <div className="skeleton w-10 h-10 rounded-xl shrink-0" />
      <div className="flex-1 space-y-2">
        <SkeletonLine className="w-3/4" />
        <SkeletonLine className="w-1/2 h-3" />
      </div>
      <SkeletonBadge />
    </div>
  );
}
