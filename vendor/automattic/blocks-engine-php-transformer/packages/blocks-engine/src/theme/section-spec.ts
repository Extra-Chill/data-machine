export type InteractionModel =
  | 'static'
  | 'cover-with-headline'
  | 'animated-cover'
  | 'media-text'
  | 'columns'
  | 'gallery'
  | 'logo-strip'
  | 'testimonial'
  | 'cta'
  | 'blog-card-grid'
  | 'project-card-grid'
  | 'price-list'
  | 'product-card-row'
  | 'review-grid'
  | 'app-download'
  | 'color-block-grid'
  | 'marquee-strip'
  | 'horizontal-showcase'
  | 'footer'
  | 'nav';

export interface ExtractedReview {
  category: string | null;
  stars: number;
  quote: string;
  author: string | null;
}

export interface ExtractedFaq {
  question: string;
  answer: string;
}

export interface SectionSpecImage {
  url: string;
  sourceUrl: string;
  alt: string;
  kind: 'img' | 'background';
  width: number;
  height: number;
}

export interface SectionSpecIcon {
  kind: 'svg' | 'glyph';
  markup?: string;
  glyph?: string;
  fontFamily?: string;
  width: number;
  height: number;
}

export interface SectionSpecButton {
  label: string;
  href: string;
  background?: string | null;
  color?: string | null;
  icon?: SectionSpecIcon | null;
  iconAfter?: boolean;
}

export interface SectionSpecFormField {
  kind:
    | 'text'
    | 'name'
    | 'email'
    | 'tel'
    | 'url'
    | 'number'
    | 'date'
    | 'checkbox'
    | 'radio'
    | 'select'
    | 'textarea'
    | 'file'
    | 'hidden'
    | 'consent';
  label: string;
  required: boolean;
  placeholder?: string;
  defaultValue?: string;
  options?: string[];
  widthPct?: 25 | 50 | 75 | 100;
}

export interface SectionSpecForm {
  fields: SectionSpecFormField[];
  submitLabel: string;
}

export interface SectionSpecCell {
  heading: string | null;
  body: string[];
  image: SectionSpecImage | null;
  icon: SectionSpecIcon | null;
  button: string | null;
  background?: string | null;
  radius?: number;
  headingSize?: number;
  headingFamily?: string;
  headingLineHeight?: number;
  bodyFamily?: string;
  bodyLineHeight?: number;
  padding?: { top: number; right: number; bottom: number; left: number } | null;
  align?: 'left' | 'center' | 'right';
  iconAlign?: 'left' | 'center' | 'right';
}

export interface SectionSpecMotion {
  motionClass:
    | 'none'
    | 'css-transition'
    | 'css-keyframes'
    | 'entry-reveal'
    | 'marquee'
    | 'carousel'
    | 'parallax'
    | 'video'
    | 'lottie'
    | 'scroll-triggered';
  signals: string[];
  animatedElements: number;
}

export interface SectionSpecLayout {
  containerWidth: number;
  padding: string;
  childLayout: 'grid' | 'flex-row' | 'flex-column' | 'stack';
  columnCount: number;
  gap: string;
  padTopPx?: number;
  padBottomPx?: number;
}

export interface SectionSpec {
  sectionIndex: number;
  interactionModel: InteractionModel;
  top: number;
  height: number;
  headings: string[];
  headingSizes?: number[];
  headingFamilies?: string[];
  headingLineHeights?: number[];
  textAlign?: 'left' | 'center' | 'right';
  mediaLayout?: 'image-left' | 'image-right' | null;
  fullBleed?: boolean;
  selector?: string;
  bodyText: string[];
  bodyTextSizes?: number[];
  bodyFamilies?: string[];
  bodyLineHeights?: number[];
  buttonLabels: string[];
  buttons?: SectionSpecButton[];
  images: SectionSpecImage[];
  icons: SectionSpecIcon[];
  backgroundBrightness: number;
  backgroundColor: string;
  gradient: string | null;
  gradientSource: 'wrapper' | 'ancestor' | 'sibling' | 'pageBackground' | 'inherited' | null;
  motionProfile: SectionSpecMotion;
  dividerAbove: { color: string; thickness: number } | null;
  dividerBelow: { color: string; thickness: number } | null;
  layout: SectionSpecLayout;
  reviews?: ExtractedReview[];
  faqs?: ExtractedFaq[];
  cells?: SectionSpecCell[];
  forms?: SectionSpecForm[];
  sectionHtml?: string;
  styledHtml?: string;
}
