// Set server-side timezone before Next.js boots
process.env.TZ = 'Asia/Kolkata';

/** @type {import('next').NextConfig} */
const nextConfig = {
  reactStrictMode: true,
};

export default nextConfig;
