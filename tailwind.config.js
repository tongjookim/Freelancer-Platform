/**
 * Tailwind CSS 설정
 * - content: 클래스명을 스캔할 대상. PHP 템플릿과 React(JSX) 소스를 모두 포함한다.
 *   (PHP 안에서 직접 Tailwind 클래스를 쓰는 경우도 놓치지 않도록 .php 를 포함)
 */
export default {
  content: [
    './*.php',
    './admin/**/*.php',
    './includes/**/*.php',
    './api/**/*.php',
    './assets/js/src/**/*.{js,jsx}',
  ],
  theme: {
    extend: {
      colors: {
        // 플랫폼 브랜드 컬러 (예시)
        brand: {
          50:  '#eef2ff',
          100: '#e0e7ff',
          500: '#6366f1',
          600: '#4f46e5',
          700: '#4338ca',
        },
      },
      fontFamily: {
        sans: ['Pretendard', 'system-ui', '-apple-system', 'sans-serif'],
      },
    },
  },
  plugins: [],
};
