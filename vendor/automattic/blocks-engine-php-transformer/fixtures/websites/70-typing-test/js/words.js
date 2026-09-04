/* ============================================================
   Keystroke — word bank, punctuation map, and quote set.
   All content is plain, common English. No external data.
   ============================================================ */
(function (global) {
  'use strict';

  // ~220 of the most common English words — the classic typing-trainer corpus.
  var WORDS = [
    'the', 'of', 'and', 'a', 'to', 'in', 'is', 'you', 'that', 'it',
    'he', 'was', 'for', 'on', 'are', 'as', 'with', 'his', 'they', 'at',
    'be', 'this', 'have', 'from', 'or', 'one', 'had', 'by', 'word', 'but',
    'not', 'what', 'all', 'were', 'we', 'when', 'your', 'can', 'said', 'there',
    'use', 'an', 'each', 'which', 'she', 'do', 'how', 'their', 'if', 'will',
    'up', 'other', 'about', 'out', 'many', 'then', 'them', 'these', 'so', 'some',
    'her', 'would', 'make', 'like', 'him', 'into', 'time', 'has', 'look', 'two',
    'more', 'write', 'go', 'see', 'number', 'no', 'way', 'could', 'people', 'my',
    'than', 'first', 'water', 'been', 'call', 'who', 'now', 'find', 'long', 'down',
    'day', 'did', 'get', 'come', 'made', 'may', 'part', 'over', 'new', 'sound',
    'take', 'only', 'little', 'work', 'know', 'place', 'year', 'live', 'me', 'back',
    'give', 'most', 'very', 'after', 'thing', 'our', 'just', 'name', 'good', 'sentence',
    'man', 'think', 'say', 'great', 'where', 'help', 'through', 'much', 'before', 'line',
    'right', 'too', 'mean', 'old', 'any', 'same', 'tell', 'boy', 'follow', 'came',
    'want', 'show', 'also', 'around', 'form', 'three', 'small', 'set', 'put', 'end',
    'does', 'another', 'well', 'large', 'must', 'big', 'even', 'such', 'because', 'turn',
    'here', 'why', 'ask', 'went', 'men', 'read', 'need', 'land', 'different', 'home',
    'us', 'move', 'try', 'kind', 'hand', 'picture', 'again', 'change', 'off', 'play',
    'spell', 'air', 'away', 'animal', 'house', 'point', 'page', 'letter', 'mother', 'answer',
    'found', 'study', 'still', 'learn', 'should', 'world', 'high', 'every', 'near', 'add',
    'food', 'between', 'own', 'below', 'country', 'plant', 'last', 'school', 'father', 'keep',
    'tree', 'never', 'start', 'city', 'earth', 'eye', 'light', 'thought', 'head', 'under'
  ];

  // Punctuation decorators applied at random when the option is on.
  var SENTENCE_END = ['.', '.', '.', '?', '!'];
  var MID = [',', ';', ':'];

  // Real, wholesome passages for quote mode (title is the topic, not an author claim).
  var QUOTES = [
    { text: 'The best way to predict the future is to build it one small, careful step at a time.', source: 'On craft' },
    { text: 'A river cuts through rock not because of its power but because of its persistence.', source: 'On patience' },
    { text: 'Simplicity is the soul of efficiency, and clear thinking is the soul of simple code.', source: 'On engineering' },
    { text: 'The forest grows quietly, ring by ring, paying no attention to how fast the grass moves.', source: 'On nature' },
    { text: 'We do not learn from experience; we learn from reflecting on the experience we have had.', source: 'On learning' },
    { text: 'Good design is as little design as possible, because less is almost always more honest.', source: 'On design' },
    { text: 'The stars are not afraid to look like fireflies, and the fireflies are content to be small.', source: 'On perspective' },
    { text: 'Practice is not the thing you do once you are good; it is the thing that makes you good.', source: 'On mastery' },
    { text: 'A garden requires patient labor and attention, for plants do not grow merely to please us.', source: 'On tending' },
    { text: 'The mind is like a parachute: it works best when it is open and steadily, calmly aware.', source: 'On thinking' }
  ];

  global.KeystrokeWords = { WORDS: WORDS, SENTENCE_END: SENTENCE_END, MID: MID, QUOTES: QUOTES };
})(window);
