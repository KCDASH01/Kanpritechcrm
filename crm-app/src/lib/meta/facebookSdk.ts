export interface FacebookLoginResponse {
  authResponse?: { code?: string };
  status?: string;
}

interface FacebookSdk {
  init(options: { appId: string; cookie: boolean; xfbml: boolean; version: string }): void;
  login(callback: (response: FacebookLoginResponse) => void, options: Record<string, unknown>): void;
}

declare global {
  interface Window {
    FB?: FacebookSdk;
    fbAsyncInit?: () => void;
  }
}

let sdkPromise: Promise<FacebookSdk> | null = null;

export function loadFacebookSdk(appId: string): Promise<FacebookSdk> {
  if (sdkPromise) return sdkPromise;

  sdkPromise = new Promise((resolve, reject) => {
    const initialize = () => {
      if (!window.FB) {
        reject(new Error('The Meta SDK did not initialize.'));
        return;
      }
      window.FB.init({
        appId,
        cookie: true,
        xfbml: false,
        version: process.env.NEXT_PUBLIC_META_GRAPH_VERSION || 'v22.0',
      });
      resolve(window.FB);
    };

    if (window.FB) {
      initialize();
      return;
    }

    window.fbAsyncInit = initialize;
    const existing = document.getElementById('facebook-jssdk');
    if (existing) return;
    const script = document.createElement('script');
    script.id = 'facebook-jssdk';
    script.async = true;
    script.defer = true;
    script.crossOrigin = 'anonymous';
    script.src = 'https://connect.facebook.net/en_US/sdk.js';
    script.onerror = () => reject(new Error('The Meta SDK could not be loaded.'));
    document.head.appendChild(script);
  });

  return sdkPromise;
}

export const META_MESSAGE_ORIGINS = new Set([
  'https://www.facebook.com',
  'https://web.facebook.com',
  'https://business.facebook.com',
]);
