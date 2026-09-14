namespace STED.RestaurantOS.Domain.Common;

/// <summary>
/// Base class for entities: identity-based equality, never value-based.
/// Two entities are the same entity when their identifiers match, whatever
/// their current state.
/// </summary>
public abstract class Entity
{
    protected Entity(Guid id) => Id = id;

    // Parameterless constructor for EF Core materialisation.
    protected Entity()
    {
    }

    public Guid Id { get; protected set; }

    public override bool Equals(object? obj)
        => obj is Entity other
           && other.GetType() == GetType()
           && other.Id != Guid.Empty
           && Id != Guid.Empty
           && other.Id == Id;

    public override int GetHashCode() => HashCode.Combine(GetType(), Id);

    public static bool operator ==(Entity? left, Entity? right) => Equals(left, right);

    public static bool operator !=(Entity? left, Entity? right) => !Equals(left, right);
}
