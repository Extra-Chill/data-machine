export type PoolEvent = {
  type:
    | 'child-spawn'
    | 'child-crash'
    | 're-route'
    | 'recycle'
    | 'sentinel'
    | 'pool-degraded';
  childId?: number;
  count?: number;
};
