'use client';

import { useEffect, useRef, useState } from 'react';

interface Props {
  open:        boolean;
  title?:      string;
  message:     string;
  subMessage?: string;
  onClose:     () => void;
  autoCloseMs?: number;
}

export function PaymentSuccessModal({
  open,
  title       = 'Payment Successful!',
  message,
  subMessage,
  onClose,
  autoCloseMs = 4000,
}: Props) {
  const [progress, setProgress] = useState(100);
  const startRef = useRef<number>(0);
  const rafRef   = useRef<number>(0);

  useEffect(() => {
    if (!open) {
      setProgress(100);
      cancelAnimationFrame(rafRef.current);
      return;
    }

    startRef.current = performance.now();

    function tick(now: number) {
      const elapsed   = now - startRef.current;
      const remaining = Math.max(0, (1 - elapsed / autoCloseMs) * 100);
      setProgress(remaining);
      if (remaining > 0) {
        rafRef.current = requestAnimationFrame(tick);
      } else {
        onClose();
      }
    }

    rafRef.current = requestAnimationFrame(tick);
    return () => cancelAnimationFrame(rafRef.current);
  }, [open, autoCloseMs, onClose]);

  if (!open) return null;

  return (
    <div className="fixed inset-0 bg-black/50 z-[60] flex items-center justify-center p-4">
      <div
        className="bg-white rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden"
        style={{ animation: 'pmSuccessIn 0.3s ease-out' }}
      >
        {/* Auto-close progress bar */}
        <div className="h-1 bg-gray-100">
          <div className="h-full bg-emerald-500" style={{ width: `${progress}%`, transition: 'width 60ms linear' }} />
        </div>

        <div className="px-8 py-8 text-center">
          {/* Animated check circle */}
          <div
            className="w-20 h-20 bg-emerald-50 rounded-full flex items-center justify-center mx-auto mb-5 border-4 border-emerald-100"
            style={{ animation: 'pmSuccessBounce 0.5s ease-out' }}
          >
            <svg
              className="w-10 h-10 text-emerald-500"
              fill="none"
              viewBox="0 0 24 24"
              stroke="currentColor"
              strokeWidth={2.5}
              strokeLinecap="round"
              strokeLinejoin="round"
            >
              <path
                d="M5 13l4 4L19 7"
                style={{
                  strokeDasharray: 30,
                  strokeDashoffset: 0,
                  animation: 'pmDrawCheck 0.4s ease-out 0.25s both',
                }}
              />
            </svg>
          </div>

          <h2 className="text-xl font-bold text-gray-900 mb-2">{title}</h2>
          <p className="text-sm text-gray-600 leading-relaxed">{message}</p>
          {subMessage && <p className="text-xs text-gray-400 mt-1.5">{subMessage}</p>}

          <button
            onClick={onClose}
            className="mt-6 w-full py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white font-semibold rounded-xl text-sm transition-colors"
          >
            Continue
          </button>
        </div>
      </div>

      <style>{`
        @keyframes pmSuccessIn {
          from { opacity: 0; transform: scale(0.88) translateY(12px); }
          to   { opacity: 1; transform: scale(1)    translateY(0);    }
        }
        @keyframes pmSuccessBounce {
          0%   { transform: scale(0);    opacity: 0; }
          55%  { transform: scale(1.18); opacity: 1; }
          100% { transform: scale(1); }
        }
        @keyframes pmDrawCheck {
          from { stroke-dashoffset: 30; }
          to   { stroke-dashoffset: 0;  }
        }
      `}</style>
    </div>
  );
}
